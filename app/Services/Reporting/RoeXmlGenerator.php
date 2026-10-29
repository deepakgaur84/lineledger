<?php

namespace App\Services\Reporting;

use App\Enums\Country;
use App\Enums\RoeReason;
use App\Exceptions\Payroll\RoeExportBlockedException;
use App\Models\Company;
use App\Models\Contact;
use App\Models\EmployeePayrollProfile;
use App\Support\Locales;
use DOMDocument;
use DOMElement;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Str;

/**
 * Writes one Record of Employment as a Service Canada ROE Web payroll extract
 * file, XML version 2.0, from the {@see RoeCalculator} data. The employer uploads
 * it in ROE Web ("Upload a Payroll Extract File"); it arrives as a draft
 * (Issue="D") to review and submit there. ROE Web wants the file named *.BLK.
 *
 * Layout and rules: "ROE Web user requirements, Appendix D: ROE payroll extract
 * file transfer, XML version 2.0" and its schema (PayrollExtractXmlV2.xsd, kept in
 * tests/fixtures/service-canada). The elements are named after the ROE form's
 * blocks:
 *
 *   B3   Employer's payroll reference (the employee ID)   B12  Final pay period ending date
 *   B5   CRA payroll account, BN + RP + 4 digits          B13  Occupation
 *   B6   Pay period type                                  B14  Expected date of recall
 *   B8   Social Insurance Number                          B15A Total insurable hours (whole, rounded up)
 *   B9   Employee name and address                        B15C Insurable earnings by pay period
 *   B10  First day worked                                 B16  Reason for issuing + payroll contact
 *   B11  Last day for which paid                          B20  Issuer's language
 *
 * There is no Block 15B: ROE Web works it out from Block 15C. The employer's name
 * and address (Block 4) come from the payroll account in ROE Web.
 *
 * The payroll account and the payroll contact come from Settings → Payroll. ROE
 * Web refuses a file whose Block 5 is not a payroll (RP) account, so without one
 * this throws {@see RoeExportBlockedException} instead of falling back to another
 * number such as the GST/HST account.
 */
class RoeXmlGenerator
{
    private const SOFTWARE = 'LineLedger';

    /**
     * One character of ROE Web's character set (Appendix D, 8.1.2): letters,
     * the accented capitals and lower-case letters it lists, digits, space and
     * its punctuation.
     */
    private const ALLOWED_CHARACTER = '/^[A-Za-z0-9 ÁÀÂÄÇÉÈÊËÍÌÎÏÓÒÔÖÚÙÛÜáàâäçéèêëíìîïóòôöúùûü"\';%!)(*#\-+\/$&@=?:~|`^_\[{\]}.,]$/u';

    /**
     * @param  array<string, mixed>  $roe  From {@see RoeCalculator::build()}
     *
     * @throws RoeExportBlockedException when Settings → Payroll lacks the payroll account or contact,
     *                                   or the employee has no posted pay
     */
    public function generate(Company $company, Contact $employee, array $roe): string
    {
        $account = $this->payrollAccount($company);
        $contact = $this->payrollContact($company);

        $problems = [];

        if ($account === null) {
            $problems[] = __('Set your CRA payroll account in Settings → Payroll: the 9-digit Business Number and the RP program account, for example 123456789 and RP0001. ROE Web files the ROE under it (Block 5) and refuses any other number.');
        }

        if ($contact === null) {
            $problems[] = __('Set the payroll contact’s first and last name and a 10-digit phone number in Settings → Payroll. ROE Web shows them in Block 16 as the person to call about the ROE.');
        }

        // Block 15C needs at least one pay period.
        if (($roe['periods'] ?? []) === []) {
            $problems[] = __('There is no posted pay for this employee yet, so the ROE has no insurable earnings to report (Block 15C). Post their pay runs first.');
        }

        if ($account === null || $contact === null || $problems !== []) {
            throw RoeExportBlockedException::because($problems);
        }

        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $header = $doc->createElement('ROEHEADER');
        $header->setAttribute('FileVersion', 'W-2.0');
        $header->setAttribute('SoftwareVendor', self::SOFTWARE);
        $header->setAttribute('ProductName', self::SOFTWARE);
        $header->setAttribute('ProductVersion', mb_substr((string) config('version.app'), 0, 10));
        $doc->appendChild($header);

        $header->appendChild($this->roe($doc, $company, $employee, $roe, $account, $contact));

        return (string) $doc->saveXML();
    }

    /**
     * @param  array<string, mixed>  $roe
     * @param  array{first: string, last: string, area: string, number: string, extension: string}  $contact
     */
    private function roe(DOMDocument $doc, Company $company, Contact $employee, array $roe, string $account, array $contact): DOMElement
    {
        $employee->loadMissing('payrollProfile');
        $profile = $employee->payrollProfile;
        $reason = RoeReason::from((string) $roe['reason']);

        $record = $doc->createElement('ROE');
        // The language of the employee's copy, and a draft to submit in ROE Web.
        $record->setAttribute('PrintingLanguage', $this->language(Locales::forDocument($employee, $company)));
        $record->setAttribute('Issue', 'D');

        $reference = $this->text($employee->employee_id, 26);

        if ($reference !== '') {
            $this->add($doc, $record, 'B3', $reference);
        }

        $this->add($doc, $record, 'B5', $account);
        $this->add($doc, $record, 'B6', (string) $roe['pay_period_type']);
        $this->add($doc, $record, 'B8', $this->sin($profile));
        $record->appendChild($this->employee($doc, $employee));
        $this->add($doc, $record, 'B10', (string) ($roe['first_day'] ?? ''));
        $this->add($doc, $record, 'B11', (string) ($roe['last_day'] ?? ''));
        $this->add($doc, $record, 'B12', (string) ($roe['final_period_end'] ?? ''));

        $occupation = $this->text($employee->job_title, 40);

        if ($occupation !== '') {
            $this->add($doc, $record, 'B13', $occupation);
        }

        $recall = $doc->createElement('B14');
        $this->add($doc, $recall, 'CD', $reason->expectedRecallCode());
        $record->appendChild($recall);

        $this->add($doc, $record, 'B15A', (string) (int) ceil(round((float) $roe['total_insurable_hours'], 2)));

        $earnings = $doc->createElement('B15C');

        foreach (array_values($roe['periods']) as $index => $period) {
            $pp = $doc->createElement('PP');
            $pp->setAttribute('nbr', (string) ($index + 1));
            $this->add($doc, $pp, 'AMT', $this->amount((int) $period['insurable_earnings_cents']));
            $earnings->appendChild($pp);
        }

        $record->appendChild($earnings);

        $issuing = $doc->createElement('B16');
        $this->add($doc, $issuing, 'CD', $reason->roeWebCode());
        $this->add($doc, $issuing, 'FN', $this->text($contact['first'], 20));
        $this->add($doc, $issuing, 'LN', $this->text($contact['last'], 28));
        $this->add($doc, $issuing, 'AC', $contact['area']);
        $this->add($doc, $issuing, 'TEL', $contact['number']);

        if ($contact['extension'] !== '') {
            $this->add($doc, $issuing, 'EXT', $contact['extension']);
        }

        $record->appendChild($issuing);

        // Block 20: the language Service Canada writes to the employer in.
        $this->add($doc, $record, 'B20', $this->language(Locales::forDocument(null, $company)));

        return $record;
    }

    /**
     * Block 9: the employee's name and home address.
     */
    private function employee(DOMDocument $doc, Contact $employee): DOMElement
    {
        $words = preg_split('/\s+/', trim((string) $employee->display_name)) ?: [];
        $displayLast = count($words) > 1 ? (string) array_pop($words) : (string) ($words[0] ?? '');
        $displayFirst = implode(' ', $words);

        $first = trim((string) $employee->first_name) !== '' ? (string) $employee->first_name : $displayFirst;
        $last = trim((string) $employee->last_name) !== '' ? (string) $employee->last_name : $displayLast;

        $block = $doc->createElement('B9');
        $this->add($doc, $block, 'FN', $this->text($first, 20));
        $this->add($doc, $block, 'LN', $this->text($last, 28));
        $this->add($doc, $block, 'A1', $this->text(trim($employee->billing_line1.' '.$employee->billing_line2), 35));
        $this->add($doc, $block, 'A2', $this->text($employee->billing_city, 35));

        $regionAndCountry = $this->text($this->regionAndCountry($employee), 35);

        if ($regionAndCountry !== '') {
            $this->add($doc, $block, 'A3', $regionAndCountry);
        }

        $this->add($doc, $block, 'PC', mb_substr(mb_strtoupper(preg_replace('/[\s-]+/', '', (string) $employee->billing_postal_code) ?? ''), 0, 10));

        return $block;
    }

    /**
     * Address line 3, the province and country: "Alberta, Canada".
     */
    private function regionAndCountry(Contact $employee): string
    {
        $country = Country::tryFrom(mb_strtoupper(trim((string) ($employee->billing_country ?: 'CA'))));
        $region = mb_strtoupper(trim((string) $employee->billing_region));
        $regionName = $country?->regions()[$region] ?? $region;

        return implode(', ', array_filter([$regionName, $country?->label() ?? trim((string) $employee->billing_country)]));
    }

    /**
     * The CRA payroll program account for Block 5, from Settings → Payroll: the
     * 9-digit Business Number with the RP account beside it (RP0001, or just
     * 0001), or all 15 characters in the Business Number field. Null when it isn't
     * one ROE Web accepts: 9 digits not all zeros, "RP", 4 digits above 0000.
     */
    private function payrollAccount(Company $company): ?string
    {
        $bn = mb_strtoupper(preg_replace('/[\s-]+/', '', (string) $company->payroll_business_number) ?? '');
        $program = mb_strtoupper(preg_replace('/[\s-]+/', '', (string) $company->payroll_rp_account) ?? '');

        if (preg_match('/^\d{9}$/', $bn) === 1) {
            $bn .= preg_match('/^\d{4}$/', $program) === 1 ? 'RP'.$program : $program;
        }

        if (preg_match('/^(\d{9})RP(\d{4})$/', $bn, $parts) !== 1 || $parts[1] === '000000000' || $parts[2] === '0000') {
            return null;
        }

        return $bn;
    }

    /**
     * The Block 16 contact, from the payroll contact in Settings → Payroll: a
     * first and a last name, and a 10-digit North American phone number split
     * into area code, number and an optional extension (up to 8 digits). Null
     * when either is missing or unusable.
     *
     * @return array{first: string, last: string, area: string, number: string, extension: string}|null
     */
    private function payrollContact(Company $company): ?array
    {
        $names = preg_split('/\s+/', trim((string) $company->payroll_contact_name)) ?: [];

        if (count($names) < 2) {
            return null;
        }

        $last = (string) array_pop($names);

        [$main, $extension] = preg_split('/\s*(?:extension|ext\.?|poste|x|#)\s*/i', (string) $company->payroll_contact_phone, 2) + [1 => ''];
        $digits = preg_replace('/\D/', '', $main) ?? '';

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }

        if (strlen($digits) !== 10) {
            return null;
        }

        return [
            'first' => implode(' ', $names),
            'last' => $last,
            'area' => substr($digits, 0, 3),
            'number' => substr($digits, 3),
            'extension' => substr(preg_replace('/\D/', '', $extension) ?? '', 0, 8),
        ];
    }

    private function sin(?EmployeePayrollProfile $profile): string
    {
        // The placeholder when no SIN is on file or it no longer decrypts (a
        // restore under another APP_KEY), as the T4 does. getAttribute() runs the
        // encrypted cast, which throws on a value it cannot decrypt.
        try {
            $sin = $profile?->getAttribute('sin_encrypted');
        } catch (DecryptException) {
            $sin = null;
        }

        return is_string($sin) && $sin !== '' ? (preg_replace('/\D/', '', $sin) ?? '000000000') : '000000000';
    }

    private function language(string $locale): string
    {
        return $locale === 'fr' ? 'F' : 'E';
    }

    /**
     * An amount in dollars with two decimals and no separators, from integer
     * cents. ROE Web takes no negative amounts.
     */
    private function amount(int $cents): string
    {
        $cents = max(0, $cents);

        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * A value cut to ROE Web's character set and the field's length: characters
     * outside the set are spelled in plain letters ("ñ" becomes "n", "’" becomes
     * "'") or dropped.
     */
    private function text(?string $value, int $max): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');
        $clean = '';

        foreach (mb_str_split($value) as $character) {
            $clean .= preg_match(self::ALLOWED_CHARACTER, $character) === 1
                ? $character
                : implode('', array_filter(
                    mb_str_split(Str::ascii($character)),
                    fn (string $c): bool => preg_match(self::ALLOWED_CHARACTER, $c) === 1,
                ));
        }

        return trim(mb_substr(trim($clean), 0, $max));
    }

    private function add(DOMDocument $doc, DOMElement $parent, string $name, string $value): void
    {
        $element = $doc->createElement($name);
        $element->appendChild($doc->createTextNode($value)); // escapes &, <, > correctly
        $parent->appendChild($element);
    }
}
