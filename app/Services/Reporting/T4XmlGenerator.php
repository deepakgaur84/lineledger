<?php

namespace App\Services\Reporting;

use App\Enums\DentalBenefitsCode;
use App\Models\Company;
use App\Models\Contact;
use App\Models\EmployeePayrollProfile;
use App\Services\Reporting\Cra\CraAddress;
use App\Services\Reporting\Cra\CraFiler;
use App\Services\Reporting\Cra\CraFilingReadiness;
use App\Services\Reporting\Cra\CraXml;
use App\Services\Reporting\Cra\T4OtherInformation;
use App\Support\Payroll\SocialInsuranceNumber;
use DOMElement;

/**
 * Generates the CRA T4 return as an Internet File Transfer XML file: the T619
 * electronic transmittal, one T4Slip per employee and the T4Summary, laid out
 * to the CRA's T4 XML specification 2027V2 and T619 2027V1 and valid against
 * the CRA schema package 1-27-1 (vendored for the tests under
 * tests/fixtures/cra-xml). Amounts are dollars and cents.
 *
 * generate() always builds the file (an unreadable SIN files the CRA's no-SIN
 * placeholder rather than failing); readiness() lists what the CRA would
 * reject. The T4 page refuses the download until readiness() is clean, and
 * any other caller must do the same.
 */
class T4XmlGenerator
{
    /** The T4 specification allows six Other Information amounts per slip. */
    private const OTHER_INFORMATION_PER_SLIP = 6;

    /** Boxes 16A / 17A (CPP2 / QPP2) and their summary totals exist from the 2024 tax year. */
    private const SECOND_CONTRIBUTIONS_FROM = 2024;

    /** @var array<string, array{0: string, 1: int}> slip box => [T4_AMT element, whole-dollar digits] */
    private const BOXES = [
        'box14' => ['empt_incamt', 8],
        'box16' => ['cpp_cntrb_amt', 4],
        'box16a' => ['cppe_cntrb_amt', 4],
        'box17' => ['qpp_cntrb_amt', 4],
        'box17a' => ['qppe_cntrb_amt', 4],
        'box18' => ['empe_eip_amt', 4],
        'box22' => ['itx_ddct_amt', 8],
        'box24' => ['ei_insu_ern_amt', 5],
        'box26' => ['cpp_qpp_ern_amt', 7],
        'box55' => ['prov_pip_amt', 4],
        'box56' => ['prov_insu_ern_amt', 6],
    ];

    /** @var array<string, string> slip T4_AMT element => the T4 Summary total (T4_TAMT) it adds to */
    private const TOTALS = [
        'empt_incamt' => 'tot_empt_incamt',
        'cpp_cntrb_amt' => 'tot_empe_cpp_amt',
        'cppe_cntrb_amt' => 'tot_empe_cppe_amt',
        'empe_eip_amt' => 'tot_empe_eip_amt',
        'rpp_cntrb_amt' => 'tot_rpp_cntrb_amt',
        'itx_ddct_amt' => 'tot_itx_ddct_amt',
        'padj_amt' => 'tot_padj_amt',
    ];

    /**
     * @param  array<int, array<string, mixed>>  $slips  From {@see T4SlipCalculator::slipsForYear()} or a finalized snapshot
     * @param  array<string, mixed>  $summary  From {@see T4SlipCalculator::summary()}; only its employer contributions are read
     */
    public function generate(Company $company, int $year, array $slips, array $summary): string
    {
        $filer = CraFiler::for($company);
        $plan = $this->plan($company, $year, $slips);
        $account = $filer->accountOrPlaceholder();

        $xml = new CraXml;
        $t4 = $xml->child($xml->submission($filer), 'T4');

        foreach ($plan['records'] as $record) {
            $this->slip($xml, $t4, $record, $account);
        }

        $t4Summary = $xml->child($t4, 'T4Summary');
        $xml->text($t4Summary, 'bn', $account);
        $xml->nameLines($t4Summary, 'EMPR_NM', $filer->name);
        $xml->address($t4Summary, 'EMPR_ADDR', $filer->address);
        $xml->summaryContact($t4Summary, $filer);
        $xml->text($t4Summary, 'tx_yr', (string) $year);
        $xml->text($t4Summary, 'slp_cnt', (string) count($plan['records']));
        $xml->text($t4Summary, 'rpt_tcd', 'O');

        $tamt = $xml->child($t4Summary, 'T4_TAMT');
        $totals = $plan['totals'];
        $second = $year >= self::SECOND_CONTRIBUTIONS_FROM;
        [$employerCpp, $employerCpp2, $employerEi] = $this->employerContributions($summary);

        $xml->amount($tamt, 'tot_empt_incamt', $totals['tot_empt_incamt']);
        $xml->amount($tamt, 'tot_empe_cpp_amt', $totals['tot_empe_cpp_amt']);
        if ($second) {
            $xml->amount($tamt, 'tot_empe_cppe_amt', $totals['tot_empe_cppe_amt']);
        }
        $xml->amount($tamt, 'tot_empe_eip_amt', $totals['tot_empe_eip_amt']);
        if ($totals['tot_rpp_cntrb_amt'] > 0) {
            $xml->amount($tamt, 'tot_rpp_cntrb_amt', $totals['tot_rpp_cntrb_amt']);
        }
        $xml->amount($tamt, 'tot_itx_ddct_amt', $totals['tot_itx_ddct_amt']);
        if ($totals['tot_padj_amt'] > 0) {
            $xml->amount($tamt, 'tot_padj_amt', $totals['tot_padj_amt']);
        }
        $xml->amount($tamt, 'tot_empr_cpp_amt', $employerCpp);
        if ($second) {
            $xml->amount($tamt, 'tot_empr_cppe_amt', $employerCpp2);
        }
        $xml->amount($tamt, 'tot_empr_eip_amt', $employerEi);

        return $xml->toXml();
    }

    /**
     * What the CRA would reject in this year's T4 file (errors) or accept but
     * flag (warnings).
     *
     * @param  array<int, array<string, mixed>>  $slips
     */
    public function readiness(Company $company, int $year, array $slips): CraFilingReadiness
    {
        $plan = $this->plan($company, $year, $slips);
        $errors = CraFiler::for($company)->problems();

        if ($slips === []) {
            $errors[] = __('There are no slips to file for :year.', ['year' => $year]);
        }

        return new CraFilingReadiness(
            array_values(array_unique([...$errors, ...$plan['errors']])),
            array_values(array_unique($plan['warnings'])),
        );
    }

    /**
     * Resolves every slip into the T4Slip records to write (an employee with
     * more than six Other Information amounts gets additional records), the
     * summary totals of what those records carry, and the problems found.
     *
     * @param  array<int, array<string, mixed>>  $slips
     * @return array{records: list<array<string, mixed>>, totals: array<string, int>, errors: list<string>, warnings: list<string>}
     */
    private function plan(Company $company, int $year, array $slips): array
    {
        $contactIds = array_map(fn (array $slip): int => (int) $slip['contact_id'], $slips);
        $contacts = Contact::query()->withoutGlobalScopes()
            ->where('company_id', $company->id)->whereIn('id', $contactIds)->get()->keyBy('id');
        $profiles = EmployeePayrollProfile::query()->withoutGlobalScopes()
            ->where('company_id', $company->id)->whereIn('contact_id', $contactIds)->get()->keyBy('contact_id');

        $records = [];
        $errors = [];
        $warnings = [];
        $totals = array_fill_keys(array_values(self::TOTALS), 0);
        $second = $year >= self::SECOND_CONTRIBUTIONS_FROM;

        foreach ($slips as $slip) {
            /** @var Contact|null $contact */
            $contact = $contacts->get((int) $slip['contact_id']);
            /** @var EmployeePayrollProfile|null $profile */
            $profile = $profiles->get((int) $slip['contact_id']);
            $name = (string) ($slip['name'] ?? '') !== '' ? (string) $slip['name'] : (string) $contact?->display_name;

            [$sin, $sinError, $sinWarning] = $this->sin($profile, $name);
            $errors = [...$errors, ...array_filter([$sinError])];
            $warnings = [...$warnings, ...array_filter([$sinWarning])];

            if (blank($contact?->last_name)) {
                $warnings[] = __(':name has no last name on file, so the slip uses the display name as the surname. Add the first and last name to the contact.', ['name' => $name]);
            }

            $province = strtoupper((string) ($slip['province'] ?? '') ?: (string) $company->address_region);
            if (! in_array($province, ['AB', 'BC', 'MB', 'NB', 'NL', 'NS', 'NT', 'NU', 'ON', 'PE', 'QC', 'SK', 'YT', 'US', 'ZZ'], true)) {
                $errors[] = __(':name has no province of employment. Set it on the employee’s payroll setup.', ['name' => $name]);
            }

            $dental = null;
            if ($year >= DentalBenefitsCode::FIRST_TAX_YEAR) {
                $dental = $profile->dental_benefits_code ?? $company->payroll_dental_benefits_code;
                if ($dental === null) {
                    $errors[] = __('Choose the employer-offered dental benefits code (T4 box 45) in Payroll settings. The CRA requires it on every T4 slip for 2023 and later.');
                }
            }

            $cpp = (int) ($slip['box16'] ?? 0) + (int) ($slip['box16a'] ?? 0);
            $qpp = (int) ($slip['box17'] ?? 0) + (int) ($slip['box17a'] ?? 0);
            if ($cpp > 0 && $qpp > 0) {
                $errors[] = __(':name has both CPP and QPP contributions for :year. The CRA needs a separate T4 slip for each province of employment, which this file can’t produce yet.', ['name' => $name, 'year' => $year]);
            }

            // T4_AMT: box 14 and the required boxes 24 and 26 always; the rest
            // only with an amount (CPP or QPP, never both; 16A/17A from 2024).
            $amounts = [];
            foreach (self::BOXES as $box => [$element, $digits]) {
                $cents = (int) ($slip[$box] ?? 0);

                if ($cents === 0 && ! in_array($box, ['box14', 'box24', 'box26'], true)) {
                    continue;
                }

                if (in_array($box, ['box16a', 'box17a'], true) && ! $second) {
                    continue;
                }

                if (in_array($box, ['box17', 'box17a'], true) && $cpp > 0) {
                    continue;
                }

                $errors = [...$errors, ...$this->amountProblems($name, __('box :box', ['box' => strtoupper(substr($box, 3))]), $cents, $digits)];
                $amounts[$element] = $cents;
            }

            $other = []; // CRA code => [OTH_INFO element, cents]
            foreach ((array) ($slip['other'] ?? []) as $code => $value) {
                $code = (string) $code;
                $cents = (int) $value;

                if ($cents === 0) {
                    continue;
                }

                if (($box = T4OtherInformation::amountBox($code)) !== null) {
                    $errors = [...$errors, ...$this->amountProblems($name, __('box :box', ['box' => $code]), $cents, $box[1])];
                    $amounts[$box[0]] = ($amounts[$box[0]] ?? 0) + $cents;
                } elseif (($element = T4OtherInformation::element($code, $year)) !== null) {
                    $errors = [...$errors, ...$this->amountProblems($name, __('code :code', ['code' => $code]), $cents, T4OtherInformation::OTHER_DIGITS)];
                    $other[(int) $code] = [$element, $cents];
                } else {
                    $errors[] = __(':name has :amount tagged with T4 box :code, which can’t be reported on a :year T4 slip. Change the T4 box on that pay item.', ['name' => $name, 'amount' => CraXml::formatAmount($cents), 'code' => $code, 'year' => $year]);
                }
            }

            foreach (self::TOTALS as $element => $total) {
                $totals[$total] += max(0, $amounts[$element] ?? 0);
            }

            $identity = [
                'surname' => CraXml::fit((string) ($contact?->last_name ?: $name), 20),
                'given' => CraXml::fit($contact?->first_name, 12),
                'address' => CraAddress::fromContact($contact),
                'sin' => $sin,
                'cpp_exempt' => $profile?->cpp_exempt ? '1' : '0',
                'ei_exempt' => $profile?->ei_exempt ? '1' : '0',
                'ppip_exempt' => $province === 'QC' ? ($profile?->qpip_exempt ? '1' : '0') : null,
                'province' => $province,
                'dental' => $dental?->value,
            ];

            // In CRA code order, six to a slip.
            ksort($other);
            $chunks = array_chunk(array_values($other), self::OTHER_INFORMATION_PER_SLIP);
            $records[] = ['identity' => $identity, 'amounts' => $amounts, 'other' => $chunks[0] ?? []];

            // An additional slip carries the identification and the further
            // Other Information amounts, without repeating the boxes.
            foreach (array_slice($chunks, 1) as $chunk) {
                $records[] = ['identity' => $identity, 'amounts' => null, 'other' => $chunk];
            }
        }

        return ['records' => $records, 'totals' => $totals, 'errors' => $errors, 'warnings' => $warnings];
    }

    /** @param  array<string, mixed>  $record */
    private function slip(CraXml $xml, DOMElement $t4, array $record, string $account): void
    {
        $identity = $record['identity'];
        $t4Slip = $xml->child($t4, 'T4Slip');

        $name = $xml->child($t4Slip, 'EMPE_NM');
        $xml->text($name, 'snm', $identity['surname']);
        $xml->text($name, 'gvn_nm', $identity['given']);
        $xml->address($t4Slip, 'EMPE_ADDR', $identity['address']);
        $xml->text($t4Slip, 'sin', $identity['sin']);
        $xml->text($t4Slip, 'bn', $account);
        $xml->text($t4Slip, 'cpp_qpp_xmpt_cd', $identity['cpp_exempt']);
        $xml->text($t4Slip, 'ei_xmpt_cd', $identity['ei_exempt']);
        $xml->text($t4Slip, 'prov_pip_xmpt_cd', $identity['ppip_exempt']);
        $xml->text($t4Slip, 'rpt_tcd', 'O');
        $xml->text($t4Slip, 'empt_prov_cd', $identity['province']);
        $xml->text($t4Slip, 'empr_dntl_ben_rpt_cd', $identity['dental'] !== null ? (string) $identity['dental'] : null);

        if ($record['amounts'] !== null) {
            $amounts = $xml->child($t4Slip, 'T4_AMT');
            foreach ($record['amounts'] as $element => $cents) {
                $xml->amount($amounts, $element, $cents);
            }
        }

        // The one place a slip's Other Information is written: every code the
        // slip carries ({@see T4OtherInformation}), in CRA code order. The
        // schema's OTH_INFO is up to six repeats of a choice, so any order
        // validates; code order keeps the file stable.
        if ($record['other'] !== []) {
            $other = $xml->child($t4Slip, 'OTH_INFO');
            foreach ($record['other'] as [$element, $cents]) {
                $xml->amount($other, $element, $cents);
            }
        }
    }

    /**
     * The SIN to file and any problem with it. The T4 specification allows
     * 000000000 only "when the employee has failed to provide a SIN", so a SIN
     * on file that is malformed or can no longer be decrypted blocks filing
     * instead of being replaced; the file itself never carries a bad SIN.
     *
     * @return array{0: string, 1: ?string, 2: ?string} [SIN to file, error, warning]
     */
    private function sin(?EmployeePayrollProfile $profile, string $name): array
    {
        $stored = $profile?->sinOrNull();
        $none = SocialInsuranceNumber::NOT_PROVIDED;

        if ($stored === null && filled($profile?->sin_last4)) {
            return [$none, __(':name: the SIN on file can’t be read. It was encrypted under a different APP_KEY, for example before a restore. Enter it again on the employee’s payroll setup.', ['name' => $name]), null];
        }

        if ($stored === null || SocialInsuranceNumber::isNotProvided($stored)) {
            return [$none, null, __(':name has no SIN on file, so the slip is filed with 000000000. The CRA accepts that only when the employee hasn’t given you a SIN, and their CPP contributions aren’t credited to them until you file the correct one.', ['name' => $name])];
        }

        if (! SocialInsuranceNumber::isValid($stored)) {
            return [$none, __(':name: the SIN on file isn’t a valid social insurance number (9 digits that pass the CRA check digit). Correct it on the employee’s payroll setup, or enter 000 000 000 if they haven’t given you one.', ['name' => $name]), null];
        }

        return [SocialInsuranceNumber::digits($stored), null, null];
    }

    /** @return list<string> */
    private function amountProblems(string $name, string $box, int $cents, int $digits): array
    {
        if ($cents < 0) {
            return [__(':name: :box is negative (:amount). The CRA doesn’t accept negative amounts on a slip.', ['name' => $name, 'box' => $box, 'amount' => CraXml::formatAmount($cents)])];
        }

        if (! CraXml::fits($cents, $digits)) {
            return [__(':name: :box (:amount) is larger than the CRA’s field for it allows.', ['name' => $name, 'box' => $box, 'amount' => CraXml::formatAmount($cents)])];
        }

        return [];
    }

    /**
     * Employer CPP (box 27, base and first additional), CPP2 (box 27A) and EI
     * (box 19). A summary snapshotted before boxes 27 and 27A were kept apart
     * holds only their sum, which then counts as box 27.
     *
     * @param  array<string, mixed>  $summary
     * @return array{0: int, 1: int, 2: int}
     */
    private function employerContributions(array $summary): array
    {
        $cpp2 = (int) ($summary['box27a'] ?? 0);
        $cpp = array_key_exists('box27', $summary) ? (int) $summary['box27'] : (int) ($summary['employer_cpp'] ?? 0) - $cpp2;

        return [$cpp, $cpp2, (int) ($summary['employer_ei'] ?? 0)];
    }
}
