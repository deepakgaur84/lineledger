<?php

namespace App\Enums;

/**
 * T4 box 45, "Employer-offered dental benefits": whether, on December 31, the
 * employee or any of their family members could access dental care insurance
 * or coverage of dental services of any kind that the employer offered.
 * Required on every T4 slip from the 2023 tax year (CRA T4 XML specification,
 * <empr_dntl_ben_rpt_cd>, values 1–5). The labels are the CRA's own wording.
 */
enum DentalBenefitsCode: int
{
    case NotEligible = 1;
    case PayeeOnly = 2;
    case PayeeSpouseAndChildren = 3;
    case PayeeAndSpouse = 4;
    case PayeeAndChildren = 5;

    /** The first tax year the CRA requires (and accepts) box 45. */
    public const FIRST_TAX_YEAR = 2023;

    public function label(): string
    {
        return match ($this) {
            self::NotEligible => __('1 – Not eligible to access any dental care insurance, or coverage of dental services of any kind'),
            self::PayeeOnly => __('2 – Payee only'),
            self::PayeeSpouseAndChildren => __('3 – Payee, spouse and dependent children'),
            self::PayeeAndSpouse => __('4 – Payee and their spouse'),
            self::PayeeAndChildren => __('5 – Payee and their dependent children'),
        };
    }

    /** @return array<int, string> value => label, for select inputs. */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
