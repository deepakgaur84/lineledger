<?php

namespace App\Enums;

use App\Models\Company;
use App\Support\Defaults\AmericanDefaults;
use App\Support\Defaults\CanadianDefaults;
use App\Support\Defaults\CompanyDefaults;
use App\Support\Defaults\GenericDefaults;

/**
 * The legal jurisdiction a company operates under. Set once at creation and
 * immutable afterward. Drives currency defaults, terminology (cheque/check,
 * postal/zip, province/state), and the seeded chart of accounts / tax codes /
 * payment methods.
 *
 * Add new cases here when expanding internationally. Each case must implement
 * every method below — there is no fallback.
 *
 * `Global` is a deliberate escape hatch rather than a real jurisdiction: it
 * covers any company outside CA/US with no jurisdiction-specific compliance
 * built in. Region, currency, and tax setup are the owner's own responsibility
 * (see GenericDefaults) — this exists so the app is usable everywhere, not so
 * it's correct everywhere. Prefer adding a proper Country case with its own
 * CompanyDefaults implementation over relying on Global once a jurisdiction's
 * rules are actually known.
 */
enum Country: string
{
    case Canada = 'CA';
    case UnitedStates = 'US';
    case Global = 'GLOBAL';

    public function label(): string
    {
        return match ($this) {
            self::Canada => 'Canada',
            self::UnitedStates => 'United States',
            self::Global => 'Other / Global',
        };
    }

    /**
     * Regional-indicator flag emoji, used in country-switching UI.
     */
    public function flag(): string
    {
        return match ($this) {
            self::Canada => '🇨🇦',
            self::UnitedStates => '🇺🇸',
            self::Global => '🌐',
        };
    }

    /**
     * Resolve the deployment's country from its request host: a `.ca` apex maps
     * to Canada, anything else (`.com`, `localhost`, …) to the United States.
     * Used to tailor the guest country-switcher banner to the site being viewed.
     *
     * Global is never inferred from host — it's only reachable by explicitly
     * picking it in the company wizard's country dropdown, or by an operator
     * setting APP_REGION=GLOBAL for a self-hosted, non-CA/US deployment.
     */
    public static function fromHost(?string $host): self
    {
        $host = mb_strtolower(trim((string) $host));

        return str_ends_with($host, '.ca') ? self::Canada : self::UnitedStates;
    }

    /**
     * Prefill only — the setup wizard requires an explicit currency pick
     * regardless of jurisdiction, so this default is never silently relied on.
     */
    public function defaultCurrencyCode(): string
    {
        return match ($this) {
            self::Canada => 'CAD',
            self::UnitedStates => 'USD',
            self::Global => 'USD',
        };
    }

    /**
     * Best-guess default IANA timezone for a new company, refined by province/
     * state when known. These jurisdictions span several zones, so this is only
     * a sensible starting point — owners can change it in company settings.
     *
     * Returns ids drawn from {@see Company::timezoneOptions()} so the
     * default always resolves to a friendly option in the settings picker. The
     * shared North American zones (Pacific/Mountain/Central/Eastern) use their
     * canonical US-city ids; these are DST-identical to the Canadian-city
     * equivalents, so the stored offset/day boundary is the same either way.
     *
     * Global has no region list to refine from, so it always returns UTC — a
     * neutral, unambiguous starting point the owner is expected to change in
     * company settings immediately after setup.
     */
    public function defaultTimezone(?string $regionCode = null): string
    {
        $region = $regionCode !== null ? mb_strtoupper($regionCode) : null;

        return match ($this) {
            self::Canada => match ($region) {
                'BC', 'YT' => 'America/Los_Angeles',
                'AB', 'NT' => 'America/Denver',
                'SK', 'MB' => 'America/Chicago',
                'NB', 'NS', 'PE' => 'America/Halifax',
                'NL' => 'America/St_Johns',
                default => 'America/New_York',
            },
            self::UnitedStates => match ($region) {
                'CA', 'WA', 'OR', 'NV' => 'America/Los_Angeles',
                'AZ' => 'America/Phoenix',
                'CO', 'UT', 'NM', 'MT', 'WY', 'ID' => 'America/Denver',
                'TX', 'IL', 'MN', 'MO', 'WI', 'IA', 'KS', 'NE', 'OK', 'AR', 'LA', 'ND', 'SD' => 'America/Chicago',
                'AK' => 'America/Anchorage',
                'HI' => 'Pacific/Honolulu',
                default => 'America/New_York',
            },
            self::Global => 'UTC',
        };
    }

    /**
     * Best-guess region code for a browser-reported IANA timezone, or null when
     * the zone spans several of this country's regions (so the owner picks).
     *
     * Most operating systems report a province/state-specific zone id
     * (America/Vancouver, America/Halifax…), which pins the region exactly.
     * Zones shared by several regions (America/New_York, America/Chicago,
     * America/Los_Angeles, America/Denver) are deliberately left unmapped: a
     * wrong tax jurisdiction is worse than an empty field, and region — unlike
     * country — stays editable after setup.
     *
     * Global has no fixed region list at all (see regions()), so this always
     * returns null — the owner types their region as free text instead.
     *
     * @return string|null one of the {@see self::regions()} keys
     */
    public function regionForTimezone(string $tz): ?string
    {
        $region = match ($this) {
            self::Canada => match ($tz) {
                'America/St_Johns', 'America/Goose_Bay' => 'NL',
                'America/Halifax', 'America/Glace_Bay' => 'NS',
                'America/Moncton' => 'NB',
                'America/Toronto', 'America/Nipigon', 'America/Thunder_Bay', 'America/Atikokan' => 'ON',
                'America/Montreal', 'America/Blanc-Sablon' => 'QC',
                'America/Winnipeg', 'America/Rainy_River' => 'MB',
                'America/Regina', 'America/Swift_Current' => 'SK',
                'America/Edmonton' => 'AB',
                'America/Yellowknife', 'America/Inuvik' => 'NT',
                'America/Iqaluit', 'America/Rankin_Inlet', 'America/Cambridge_Bay', 'America/Resolute' => 'NU',
                'America/Vancouver', 'America/Dawson_Creek', 'America/Fort_Nelson', 'America/Creston' => 'BC',
                'America/Whitehorse', 'America/Dawson' => 'YT',
                default => null,
            },
            self::UnitedStates => match ($tz) {
                'America/Phoenix' => 'AZ',
                'Pacific/Honolulu' => 'HI',
                'America/Anchorage', 'America/Juneau', 'America/Sitka', 'America/Nome', 'America/Yakutat', 'America/Adak' => 'AK',
                'America/Detroit', 'America/Menominee' => 'MI',
                'America/Boise' => 'ID',
                default => null,
            },
            self::Global => null,
        };

        // Never return a code we don't actually offer as a selectable option.
        return $region !== null && array_key_exists($region, $this->regions()) ? $region : null;
    }

    /**
     * Global uses a neutral, generic label since "state" and "province" are
     * both wrong more often than right outside North America.
     */
    public function regionLabel(): string
    {
        return match ($this) {
            self::Canada => 'Province',
            self::UnitedStates => 'State',
            self::Global => 'Region',
        };
    }

    public function postalCodeLabel(): string
    {
        return match ($this) {
            self::Canada => 'Postal Code',
            self::UnitedStates => 'ZIP Code',
            self::Global => 'Postal Code',
        };
    }

    /**
     * Global returns a generic label since there's no fixed tax regime — the
     * owner defines their own tax codes and agency (see GenericDefaults, which
     * seeds neither), so this is just display text for whatever they set up.
     */
    public function taxLabel(): string
    {
        return match ($this) {
            self::Canada => 'GST/HST',
            self::UnitedStates => 'Sales Tax',
            self::Global => 'Tax',
        };
    }

    /**
     * @return array<string, string> code => name (e.g. 'BC' => 'British Columbia')
     */
    public function regions(): array
    {
        return match ($this) {
            self::Canada => [
                'AB' => 'Alberta',
                'BC' => 'British Columbia',
                'MB' => 'Manitoba',
                'NB' => 'New Brunswick',
                'NL' => 'Newfoundland and Labrador',
                'NS' => 'Nova Scotia',
                'NT' => 'Northwest Territories',
                'NU' => 'Nunavut',
                'ON' => 'Ontario',
                'PE' => 'Prince Edward Island',
                'QC' => 'Quebec',
                'SK' => 'Saskatchewan',
                'YT' => 'Yukon',
            ],
            self::UnitedStates => [
                'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas',
                'CA' => 'California', 'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware',
                'DC' => 'District of Columbia', 'FL' => 'Florida', 'GA' => 'Georgia', 'HI' => 'Hawaii',
                'ID' => 'Idaho', 'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa',
                'KS' => 'Kansas', 'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine',
                'MD' => 'Maryland', 'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota',
                'MS' => 'Mississippi', 'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska',
                'NV' => 'Nevada', 'NH' => 'New Hampshire', 'NJ' => 'New Jersey', 'NM' => 'New Mexico',
                'NY' => 'New York', 'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio',
                'OK' => 'Oklahoma', 'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'RI' => 'Rhode Island',
                'SC' => 'South Carolina', 'SD' => 'South Dakota', 'TN' => 'Tennessee', 'TX' => 'Texas',
                'UT' => 'Utah', 'VT' => 'Vermont', 'VA' => 'Virginia', 'WA' => 'Washington',
                'WV' => 'West Virginia', 'WI' => 'Wisconsin', 'WY' => 'Wyoming',
            ],
            // Deliberately empty: the setup wizard and company edit form both
            // already fall back to a plain free-text "Region" input whenever
            // regions() returns []. No dropdown to maintain, no wrong list to
            // offer a New Zealand (or any other) user.
            self::Global => [],
        };
    }

    /**
     * Singular or plural noun for cheque/check, sentence-cased.
     * Pass 'singular' (default) or 'plural'.
     *
     * Global defaults to the international "Cheque" spelling rather than the
     * US "Check" — most countries outside the US use it, and it reads oddly
     * only to US users, who have their own dedicated Country case anyway.
     */
    public function cheque(string $form = 'singular'): string
    {
        $singular = match ($this) {
            self::Canada => 'Cheque',
            self::UnitedStates => 'Check',
            self::Global => 'Cheque',
        };

        return $form === 'plural' ? $singular.'s' : $singular;
    }

    /**
     * Pre-baked labels used in cheque-related forms and buttons. Keys:
     *   - "number"      e.g. "Cheque #" / "Check #"
     *   - "edit"        e.g. "Edit cheque" / "Edit check"
     *   - "write"       e.g. "Write cheque" / "Write check"
     *   - "post"        e.g. "Post cheque" / "Post check"
     *   - "print"       e.g. "Print cheque" / "Print check"
     *   - "ref"         e.g. "Cheque # or txn ref"
     *   - "section"     e.g. "Cheques and Payments"
     *   - "checkbook"   e.g. "Chequebook view" / "Checkbook view"
     *   - "method"      e.g. "Cheque method" / "Check method"
     */
    public function chequeLabel(string $key): string
    {
        $singular = $this->cheque('singular');
        $plural = $this->cheque('plural');
        $lower = mb_strtolower($singular);
        $lowerPlural = mb_strtolower($plural);

        return match ($key) {
            'number' => $singular.' #',
            'edit' => 'Edit '.$lower,
            'write' => 'Write '.$lower,
            'post' => 'Post '.$lower,
            'print' => 'Print '.$lower,
            'ref' => $singular.' # or txn ref',
            'section' => $plural.' and Payments',
            'checkbook' => match ($this) {
                self::Canada => 'Chequebook view',
                self::UnitedStates => 'Checkbook view',
                self::Global => 'Chequebook view',
            },
            'method' => $singular.' method',
            'plural' => $plural,
            'singular' => $singular,
            default => $singular,
        };
    }

    /**
     * Global reuses GenericDefaults as-is: a jurisdiction-neutral chart of
     * accounts with no preset tax agency or tax codes. This is a deliberate
     * choice, not a placeholder — pretending to know a tax regime we don't
     * would be worse than seeding nothing and letting the owner set up their
     * own tax code (e.g. a flat-rate GST/VAT) after creation.
     */
    public function defaults(): CompanyDefaults
    {
        return match ($this) {
            self::Canada => new CanadianDefaults,
            self::UnitedStates => new AmericanDefaults,
            self::Global => new GenericDefaults,
        };
    }

    /**
     * Country cases offered as form options, in declaration order
     * (Canada, United States, Global).
     *
     * @return array<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $c) => ['value' => $c->value, 'label' => $c->label()],
            self::cases(),
        );
    }
}
