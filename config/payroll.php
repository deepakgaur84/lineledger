<?php

return [

    /*
    |--------------------------------------------------------------------------
    | CRA electronic filing (T4 / T4A XML)
    |--------------------------------------------------------------------------
    |
    | The T4 and T4A XML follow the CRA's 2027 XML specifications (T619
    | 2027V1, T4 2027V2, T4A 2027V1) and validate against its schema package
    | 1-27-1, vendored in tests/fixtures/cra-xml with the test that checks
    | every generated file. The CRA revises them each fall: compare a new
    | package and the "What's new" notes, then add its version there.
    |
    | The T619 transmittal no longer has an "MM" transmitter number (the old
    | CRA_TRANSMITTER_NUMBER / CRA_TRANSMITTER_TYPE are not read). It names
    | the account you sign in to Internet File Transfer with: each
    | organization's payroll (RP) account from Settings → Payroll, plus the
    | representative ID set there when a representative uploads the file.
    | The download is refused until that account and the payroll contact are
    | set.
    |
    | language: E or F for the CRA's correspondence about the file; unset, it
    | follows each organization's language.
    |
    */

    'transmitter' => [
        'language' => env('CRA_TRANSMITTER_LANGUAGE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Revenu Québec electronic filing (RL-1 XML)
    |--------------------------------------------------------------------------
    |
    | Settings used when generating the Revenu Québec RL-1 XML submission. The
    | transmitter number ("numéro d'identification", NPxxxxxx) and the slip
    | preparer/source numbers are assigned by Revenu Québec and MUST be set
    | before you file. As with the T4, the RL-1 XML layout is revised yearly —
    | validate against the current Revenu Québec schema before submitting.
    |
    */

    'rl1' => [
        'transmitter_number' => env('RQ_TRANSMITTER_NUMBER', 'NP000000'),
        'preparer_number' => env('RQ_PREPARER_NUMBER', 'NP000000'),
        'slip_type' => env('RQ_RL1_SLIP_TYPE', 'R'), // R = original

        // Printing OFFICIAL paper RL-1 slips requires a Revenu Québec
        // authorization number (FS-followed-by-seven-digits), obtained through
        // the "My Account for Partners" vendor process, printed on each copy.
        // Until one is configured, RL-1 PDFs print as a working copy stamped
        // "not for filing" — file via the RL-1 XML instead.
        'authorization_number' => env('RQ_AUTHORIZATION_NUMBER'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Official slip templates (T4 / T4A / RL-1 PDFs)
    |--------------------------------------------------------------------------
    |
    | Year-end slips print onto the OFFICIAL government form when a flattened
    | copy of the year's fillable PDF is installed (the app ships with the CRA
    | T4 for recent years). Resolution order, exact year only:
    |
    |   storage/app/slip-templates/{year}/{t4|t4a|rl1}.pdf   (per deployment)
    |   resources/pdf-templates/slips/{year}/{...}.pdf       (shipped)
    |
    | CRA's fillable PDFs are encrypted XFA documents; flatten them first:
    |   gs -q -o t4.pdf -sDEVICE=pdfwrite -dCompatibilityLevel=1.4 t4-fill-25e.pdf
    |
    | Verify an installed year with: php artisan payroll:verify-slip-templates
    | A year without a template falls back to a clearly-labelled facsimile.
    |
    */

];
