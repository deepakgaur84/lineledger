<?php

namespace App\Services\Reporting\Cra;

/**
 * Where the T4 XML puts the amounts pay items tag with a T4 box (the slip's
 * `other` totals): the four boxes with their own T4_AMT element, and the
 * Other Information codes of the CRA's T4 XML specification (2027V2, OTH_INFO)
 * with the tax years each is valid for — EFV error 303 rejects a code outside
 * them. Code 73 (a day count, not an amount) and code 90 (for CRA use only)
 * are not reportable from a pay item.
 */
final class T4OtherInformation
{
    /** @var array<int, array{0: string, 1: int}> box => [T4_AMT element, whole-dollar digits] */
    private const AMOUNT_BOXES = [
        '20' => ['rpp_cntrb_amt', 5],
        '44' => ['unn_dues_amt', 7],
        '46' => ['chrty_dons_amt', 7],
        '52' => ['padj_amt', 5],
    ];

    /** @var array<int, array{0: string, 1: ?int, 2: ?int}> code => [OTH_INFO element, first tax year, last tax year] */
    private const CODES = [
        '30' => ['hm_brd_lodg_amt', null, null],
        '31' => ['spcl_wrk_site_amt', null, null],
        '32' => ['prscb_zn_trvl_amt', null, null],
        '33' => ['med_trvl_amt', null, null],
        '34' => ['prsnl_vhcl_amt', null, null],
        '35' => ['rsn_per_km_amt', null, 2000],
        '36' => ['low_int_loan_amt', null, null],
        '37' => ['empe_hm_loan_amt', null, null],
        '38' => ['sob_a00_feb_amt', null, null],
        '39' => ['sod_d_a00_feb_amt', null, null],
        '40' => ['oth_tx_ben_amt', null, null],
        '41' => ['sod_d1_a00_feb_amt', null, null],
        '42' => ['empt_cmsn_amt', null, null],
        '43' => ['cfppa_amt', null, null],
        '53' => ['dfr_sob_amt', null, null],
        '57' => ['empt_inc_amt_covid_prd1', 2020, 2020],
        '58' => ['empt_inc_amt_covid_prd2', 2020, 2020],
        '59' => ['empt_inc_amt_covid_prd3', 2020, 2020],
        '60' => ['empt_inc_amt_covid_prd4', 2020, 2020],
        '66' => ['elg_rtir_amt', null, null],
        '67' => ['nelg_rtir_amt', null, null],
        '69' => ['indn_nelg_rtir_amt', null, null],
        '71' => ['indn_empe_amt', null, null],
        '72' => ['oc_incamt', null, null],
        '74' => ['pr_90_cntrbr_amt', null, null],
        '75' => ['pr_90_ncntrbr_amt', null, null],
        '77' => ['cmpn_rpay_empr_amt', null, null],
        '78' => ['fish_gro_ern_amt', null, null],
        '79' => ['fish_net_ptnr_amt', null, null],
        '80' => ['fish_shr_prsn_amt', null, null],
        '81' => ['plcmt_emp_agcy_amt', null, null],
        '82' => ['drvr_taxis_oth_amt', null, null],
        '83' => ['brbr_hrdrssr_amt', null, null],
        '84' => ['pub_trnst_pass_amt', null, null],
        '85' => ['epaid_hlth_pln_amt', null, null],
        '86' => ['stok_opt_csh_out_eamt', null, null],
        '87' => ['vlntr_emergencyworker_xmpt_amt', 2011, null],
        '88' => ['indn_txmpt_sei_amt', null, null],
        '91' => ['sod_d_after_jun2024_amt', null, null],
        '92' => ['sod_d1_after_jun2024_amt', null, null],
        '94' => ['indn_xmpt_rpp_amt', null, null],
        '95' => ['indn_xmpt_unn_amt', null, null],
        '96' => ['elg_prsnl_spprt_wrkr_inc_amt', 2026, 2030],
        '97' => ['stok_opt_ben_amt', null, 2000],
        '98' => ['shr_opt_d_ben_amt', null, 2000],
        '99' => ['shr_opt_d1_ben_amt', null, 2000],
    ];

    /** Other Information amounts are "up to 9 numeric": 7 whole-dollar digits. */
    public const OTHER_DIGITS = 7;

    /** @return array{0: string, 1: int}|null [T4_AMT element, whole-dollar digits] for boxes 20, 44, 46 and 52 */
    public static function amountBox(string $box): ?array
    {
        return self::twoDigits($box) ? (self::AMOUNT_BOXES[(int) $box] ?? null) : null;
    }

    /** The OTH_INFO element for $code in $year, or null when the code is not one for that year. */
    public static function element(string $code, int $year): ?string
    {
        $definition = self::twoDigits($code) ? (self::CODES[(int) $code] ?? null) : null;

        if ($definition === null) {
            return null;
        }

        [$element, $from, $to] = $definition;

        return ($from === null || $year >= $from) && ($to === null || $year <= $to) ? $element : null;
    }

    /** T4 boxes and Other Information codes are two digits ("40", never "040"). */
    private static function twoDigits(string $code): bool
    {
        return preg_match('/^\d{2}$/', $code) === 1;
    }
}
