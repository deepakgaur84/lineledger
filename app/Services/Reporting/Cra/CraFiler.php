<?php

namespace App\Services\Reporting\Cra;

use App\Models\Company;
use App\Support\Payroll\PayrollContactPhone;

/**
 * Who files a T4 or T4A return, read from the organization and its Payroll
 * settings: the payroll program account (BN15) the slips are filed under, the
 * name, address and language, the contact the CRA reaches about the file,
 * and the representative ID when a representative uploads it.
 *
 * The T619 transmittal names the account used to sign in to Internet File
 * Transfer (IFT rejects a mismatch). An organization filing its own return
 * signs in through My Business Account or with a web access code under that
 * same payroll account, which the T619 accepts as a BN15 either way; a
 * representative signing in through Represent a Client adds their RepID.
 */
final readonly class CraFiler
{
    /** The all-zero payroll account a file carries only while readiness() reports it missing. */
    public const PLACEHOLDER_ACCOUNT = '000000000RP0000';

    public function __construct(
        public ?string $account,
        public bool $accountEntered,
        public string $name,
        public CraAddress $address,
        public ?string $contactName,
        public ?PayrollContactPhone $contactPhone,
        public ?string $contactEmail,
        public ?string $repId,
        public string $languageCode,
    ) {}

    public static function for(Company $company): self
    {
        $email = trim((string) $company->payroll_contact_email);
        $repId = strtoupper(trim((string) $company->payroll_cra_rep_id));

        return new self(
            account: $company->payrollAccountNumber(),
            accountEntered: filled($company->payroll_business_number) && filled($company->payroll_rp_account),
            name: CraXml::fit($company->legal_name ?: $company->name, 100) ?? '',
            address: CraAddress::fromCompany($company),
            contactName: CraXml::fit($company->payroll_contact_name, 100),
            contactPhone: PayrollContactPhone::parse($company->payroll_contact_phone),
            contactEmail: $email !== '' ? $email : null,
            repId: preg_match('/^[A-Z0-9]{1,7}$/', $repId) === 1 ? $repId : null,
            languageCode: self::languageCode($company),
        );
    }

    /** The payroll account to write: the real one, or the placeholder in a file readiness() refuses. */
    public function accountOrPlaceholder(): string
    {
        return $this->account ?? self::PLACEHOLDER_ACCOUNT;
    }

    /**
     * What stops the CRA accepting any T4 or T4A file from this filer.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];

        if ($this->account === null) {
            $problems[] = $this->accountEntered
                ? __('The Business Number and payroll (RP) account in Payroll settings don’t form a valid payroll account: 9 digits, RP, then 4 digits, for example 123456789RP0001.')
                : __('Enter your Business Number and payroll (RP) program account in Payroll settings. The CRA files T4 and T4A slips under that account (for example 123456789RP0001), not under your GST/HST number.');
        }

        if ($this->name === '') {
            $problems[] = __('Enter your organization’s name. The CRA requires the filer’s name on the transmittal and on the summary.');
        }

        if ($this->contactName === null) {
            $problems[] = __('Enter the payroll contact name in Payroll settings. The CRA requires a contact on the transmittal and on the summary.');
        }

        if ($this->contactPhone === null) {
            $problems[] = __('Enter the payroll contact phone in Payroll settings as a 10-digit number, for example 613-555-0101.');
        }

        if ($this->contactEmail === null) {
            $problems[] = __('Enter the payroll contact email in Payroll settings. The CRA sends its messages about the file there.');
        } elseif (mb_strlen($this->contactEmail) > 60) {
            $problems[] = __('The payroll contact email in Payroll settings is longer than the 60 characters the CRA accepts. Use a shorter address.');
        }

        return $problems;
    }

    /** E or F: CRA_TRANSMITTER_LANGUAGE when set, otherwise the organization's language. */
    private static function languageCode(Company $company): string
    {
        $configured = strtoupper(trim((string) config('payroll.transmitter.language')));

        if (in_array($configured, ['E', 'F'], true)) {
            return $configured;
        }

        return str_starts_with((string) $company->locale, 'fr') ? 'F' : 'E';
    }
}
