<?php

namespace App\Services\Reporting\Cra;

use App\Enums\Country;
use App\Models\Company;
use App\Models\Contact;

/**
 * A Canadian or US address the way the CRA's T4/T4A XML carries it (the
 * *_ADDR blocks): lines of up to 30 characters and a city of up to 28 (the
 * specifications' lengths), an upper-case two-letter province or state, an
 * ISO country code and a postal code (A9A9A9) or ZIP code. Parts that do not
 * fit those formats are dropped rather than sent malformed; an address in any
 * other country is left out (the block is optional).
 */
final readonly class CraAddress
{
    public function __construct(
        public ?string $line1 = null,
        public ?string $line2 = null,
        public ?string $city = null,
        public ?string $province = null,
        public ?string $country = null,
        public ?string $postalCode = null,
    ) {}

    public static function fromCompany(Company $company): self
    {
        return self::make($company->address_line1, $company->address_line2, $company->address_city, $company->address_region, $company->address_country, $company->address_postal_code);
    }

    public static function fromContact(?Contact $contact): self
    {
        if ($contact === null) {
            return new self;
        }

        return self::make($contact->billing_line1, $contact->billing_line2, $contact->billing_city, $contact->billing_region, $contact->billing_country, $contact->billing_postal_code);
    }

    public function isEmpty(): bool
    {
        return $this->line1 === null && $this->line2 === null && $this->city === null
            && $this->province === null && $this->postalCode === null;
    }

    private static function make(?string $line1, ?string $line2, ?string $city, ?string $region, ?string $country, ?string $postal): self
    {
        $country = match (strtoupper(trim((string) $country))) {
            '', 'CA', 'CAN', 'CANADA' => Country::Canada,
            'US', 'USA', 'UNITED STATES' => Country::UnitedStates,
            default => null,
        };

        if ($country === null) {
            return new self;
        }

        $address = new self(
            line1: CraXml::fit($line1, 30),
            line2: CraXml::fit($line2, 30),
            city: CraXml::fit($city, 28),
            province: self::region($country, $region),
            country: $country === Country::Canada ? 'CAN' : 'USA',
            postalCode: self::postalCode($country, $postal),
        );

        return $address->isEmpty() ? new self : $address;
    }

    private static function region(Country $country, ?string $region): ?string
    {
        $regions = $country->regions();
        $value = trim((string) $region);
        $code = strtoupper($value);

        if (array_key_exists($code, $regions)) {
            return $code;
        }

        $byName = array_search(mb_strtolower($value), array_map('mb_strtolower', $regions), true);

        return is_string($byName) ? $byName : null;
    }

    private static function postalCode(Country $country, ?string $postal): ?string
    {
        $value = strtoupper(trim((string) $postal));

        if ($country === Country::Canada) {
            $value = str_replace([' ', '-'], '', $value);

            return preg_match('/^[A-Z]\d[A-Z]\d[A-Z]\d$/', $value) === 1 ? $value : null;
        }

        return preg_match('/^\d{5}(-\d{4})?$/', $value) === 1 ? $value : null;
    }
}
