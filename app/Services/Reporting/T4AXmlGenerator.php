<?php

namespace App\Services\Reporting;

use App\Models\Company;
use App\Models\Contact;
use App\Services\Reporting\Cra\CraAddress;
use App\Services\Reporting\Cra\CraFiler;
use App\Services\Reporting\Cra\CraFilingReadiness;
use App\Services\Reporting\Cra\CraXml;
use App\Support\Payroll\PayrollAccountNumber;
use App\Support\Payroll\SocialInsuranceNumber;
use DOMElement;

/**
 * Generates the CRA T4A return as an Internet File Transfer XML file: the T619
 * electronic transmittal, one T4ASlip per reportable contractor (fees for
 * services, box 048) and the T4ASummary, laid out to the CRA's T4A XML
 * specification 2027V1 and T619 2027V1 and valid against the CRA schema
 * package 1-27-1. The Canadian analog of the 1099 e-file; sibling of
 * {@see T4XmlGenerator}, whose readiness contract it shares: generate() always
 * builds the file, readiness() lists what the CRA would reject, and the T4A
 * page refuses the download until readiness() is clean.
 *
 * A slip names an individual (RCPNT_NM, with their SIN) or a business
 * (RCPNT_CORP_NM, with its business number), never both. Both the SIN and the
 * recipient business number are required elements, so the one that does not
 * apply carries the CRA's placeholder: 000000000 and 000000000RT0000.
 */
class T4AXmlGenerator
{
    /** The CRA's value for a recipient business number that was not provided. */
    public const NO_BUSINESS_NUMBER = '000000000RT0000';

    /**
     * @param  array<int, array<string, mixed>>  $slips  From {@see T4ASlipCalculator::slipsForYear()} or a finalized snapshot
     * @param  array<string, mixed>  $summary  From {@see T4ASlipCalculator::summary()}; the totals are summed from $slips
     */
    public function generate(Company $company, int $year, array $slips, array $summary): string
    {
        $filer = CraFiler::for($company);
        $plan = $this->plan($company, $slips);
        $account = $filer->accountOrPlaceholder();

        $xml = new CraXml;
        $t4a = $xml->child($xml->submission($filer), 'T4A');

        foreach ($plan['records'] as $record) {
            $this->slip($xml, $t4a, $record, $account);
        }

        $t4aSummary = $xml->child($t4a, 'T4ASummary');
        $xml->text($t4aSummary, 'bn', $account);
        $xml->nameLines($t4aSummary, 'PAYR_NM', $filer->name);
        $xml->address($t4aSummary, 'PAYR_ADDR', $filer->address);
        $xml->summaryContact($t4aSummary, $filer);
        $xml->text($t4aSummary, 'tx_yr', (string) $year);
        $xml->text($t4aSummary, 'slp_cnt', (string) count($plan['records']));
        $xml->text($t4aSummary, 'rpt_tcd', 'O');
        $xml->amount($xml->child($t4aSummary, 'T4A_TAMT'), 'rpt_tot_fee_srvc_amt', $plan['total']);

        return $xml->toXml();
    }

    /**
     * What the CRA would reject in this year's T4A file (errors) or accept but
     * flag (warnings).
     *
     * @param  array<int, array<string, mixed>>  $slips
     */
    public function readiness(Company $company, int $year, array $slips): CraFilingReadiness
    {
        $plan = $this->plan($company, $slips);
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
     * @param  array<int, array<string, mixed>>  $slips
     * @return array{records: list<array<string, mixed>>, total: int, errors: list<string>, warnings: list<string>}
     */
    private function plan(Company $company, array $slips): array
    {
        $contacts = Contact::query()->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereIn('id', array_map(fn (array $slip): int => (int) $slip['contact_id'], $slips))
            ->get()->keyBy('id');

        $records = [];
        $errors = [];
        $warnings = [];
        $total = 0;

        foreach ($slips as $slip) {
            /** @var Contact|null $contact */
            $contact = $contacts->get((int) $slip['contact_id']);
            $name = (string) ($slip['name'] ?? '') !== '' ? (string) $slip['name'] : (string) $contact?->display_name;
            $fee = (int) ($slip['box048_cents'] ?? 0);

            // The vendor's tax number as it is now (a corrected SIN applies to a
            // finalized year too); the one the slip was built with only when the
            // vendor no longer exists.
            $taxNumber = $contact !== null ? $contact->tax_number : ($slip['tax_number'] ?? null);
            $recipient = $this->recipient($contact, $name, $taxNumber);
            $errors = [...$errors, ...$recipient['errors']];
            $warnings = [...$warnings, ...$recipient['warnings']];

            if ($fee < 0) {
                $errors[] = __(':name: :box is negative (:amount). The CRA doesn’t accept negative amounts on a slip.', ['name' => $name, 'box' => __('box :box', ['box' => '048']), 'amount' => CraXml::formatAmount($fee)]);
            } elseif (! CraXml::fits($fee, 9)) {
                $errors[] = __(':name: :box (:amount) is larger than the CRA’s field for it allows.', ['name' => $name, 'box' => __('box :box', ['box' => '048']), 'amount' => CraXml::formatAmount($fee)]);
            }

            $total += max(0, $fee);
            $records[] = $recipient + ['fee' => $fee, 'address' => CraAddress::fromContact($contact)];
        }

        return ['records' => $records, 'total' => $total, 'errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * Who the slip is for and under which identifiers. A contact with a
     * personal name is an individual; one with a company name or a business
     * number, a business; otherwise a bare nine-digit tax number is taken as a
     * SIN (an individual) and anything else as a business. A malformed SIN or
     * business number blocks filing; none at all files the CRA's placeholder.
     *
     * @return array{individual: bool, surname: ?string, given: ?string, corporation: string, sin: string, bn: string, errors: list<string>, warnings: list<string>}
     */
    private function recipient(?Contact $contact, string $name, ?string $taxNumber): array
    {
        $number = PayrollAccountNumber::normalize($taxNumber);
        $businessNumber = preg_match('/^\d{9}[A-Z]{2}\d{4}$/', $number) === 1 ? $number : null;
        $nineDigits = preg_match('/^\d{9}$/', $number) === 1;
        $individual = filled($contact?->first_name) || filled($contact?->last_name)
            || (blank($contact?->company_name) && $businessNumber === null && $nineDigits);

        $sin = SocialInsuranceNumber::NOT_PROVIDED;
        $errors = [];
        $warnings = [];
        $invalid = __(':name: the tax number on file isn’t a valid SIN (9 digits that pass the CRA check digit) or a 15-character business number (for example 123456789RT0001). Correct it on the vendor.', ['name' => $name]);

        if ($number === '' || ($nineDigits && SocialInsuranceNumber::isNotProvided($number))) {
            $warnings[] = __(':name has no SIN or business number on file, so the slip is filed with the CRA’s placeholder. Add it to the vendor so the CRA can match the slip.', ['name' => $name]);
        } elseif ($businessNumber === null && ! $nineDigits) {
            $errors[] = $invalid;
        } elseif ($nineDigits && $individual && SocialInsuranceNumber::isValid($number)) {
            $sin = $number;
        } elseif ($nineDigits && $individual) {
            $errors[] = $invalid;
        } elseif ($nineDigits) {
            $warnings[] = __(':name: enter the vendor’s full 15-character business number (for example 123456789RT0001). Until then the slip is filed with the CRA’s placeholder.', ['name' => $name]);
        }

        if ($individual && blank($contact?->last_name)) {
            $warnings[] = __(':name has no last name on file, so the slip uses the display name as the surname. Add the first and last name to the contact.', ['name' => $name]);
        }

        return [
            'individual' => $individual,
            'surname' => CraXml::fit((string) ($contact?->last_name ?: $name), 20),
            'given' => CraXml::fit($contact?->first_name, 12),
            'corporation' => (string) ($contact?->company_name ?: $name),
            'sin' => $sin,
            'bn' => $businessNumber ?? self::NO_BUSINESS_NUMBER,
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /** @param  array<string, mixed>  $record */
    private function slip(CraXml $xml, DOMElement $t4a, array $record, string $account): void
    {
        $t4aSlip = $xml->child($t4a, 'T4ASlip');

        if ($record['individual']) {
            $name = $xml->child($t4aSlip, 'RCPNT_NM');
            $xml->text($name, 'snm', $record['surname']);
            $xml->text($name, 'gvn_nm', $record['given']);
        }

        $xml->text($t4aSlip, 'sin', $record['sin']);
        $xml->text($t4aSlip, 'rcpnt_bn', $record['bn']);

        if (! $record['individual']) {
            $xml->nameLines($t4aSlip, 'RCPNT_CORP_NM', $record['corporation']);
        }

        $xml->address($t4aSlip, 'RCPNT_ADDR', $record['address']);
        $xml->text($t4aSlip, 'bn', $account);
        $xml->text($t4aSlip, 'rpt_tcd', 'O');
        $xml->amount($xml->child($t4aSlip, 'T4A_AMT'), 'fee_or_oth_srvc_amt', $record['fee']); // box 048
    }
}
