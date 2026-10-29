<?php

namespace App\Services\Reporting;

use App\Exceptions\Payroll\Rl1ExportBlockedException;
use App\Models\Company;
use App\Models\Contact;
use App\Models\EmployeePayrollProfile;
use DOMDocument;
use DOMElement;

/**
 * Generates a Revenu Québec RL-1 (Relevé 1) XML document for a tax year: a
 * transmitter record, one Releve1 per Quebec employee, and a summary (Sommaire)
 * carrying the box totals and the WSDRF reconciliation. Amounts are emitted in
 * dollars (2 decimals).
 *
 * The boxes follow Revenu Québec's guide RL-1.G-V (2026-10): A (employment
 * income, taxable benefits included), B.A (base and first additional QPP), B.B
 * (second additional QPP), C (EI premiums), E (Quebec income tax), G (QPP
 * pensionable salary), H (QPIP premiums), I (QPIP eligible salary). The guide
 * says to leave B.A, B.B, C, E and H blank when nothing was withheld, so those
 * elements are left out at zero; A, G and I are always present. The summary
 * splits the employer QPP contribution like the RL-1 summary's lines 22 and 24.
 *
 * Every slip and the summary carry the employer's Revenu Québec identification
 * number (10 digits) and file number (RS followed by 4 digits) from Settings →
 * Payroll, the account the slips are filed under; without them this throws
 * {@see Rl1ExportBlockedException} rather than filing under another number.
 *
 * IMPORTANT — Revenu Québec accepts RL-slip XML only from software it has
 * certified for the taxation year (certification number RQ-..-..-...), sent by
 * a registered transmitter (config payroll.rl1.transmitter_number, NP followed
 * by 6 digits), and it gives the XML schema to registered partners only. This
 * document follows the boxes of the RL-1 guide, not a schema it was validated
 * against, so treat it as the box data to file through certified software or to
 * enter in My Account for businesses. Slip numbers ("numéro du relevé") are
 * assigned by Revenu Québec; the sequential numbers here are placeholders.
 * Printing official paper slips needs Revenu Québec's authorization number (see
 * config payroll.rl1.authorization_number).
 */
class Rl1XmlGenerator
{
    /**
     * @param  array<int, array<string, mixed>>  $slips  From {@see Rl1SlipCalculator::slipsForYear()}
     * @param  array<string, mixed>  $summary  From {@see Rl1SlipCalculator::summary()}
     *
     * @throws Rl1ExportBlockedException when the Revenu Québec account is not set, or the slips
     *                                   predate boxes B.A, B.B and C
     */
    public function generate(Company $company, int $year, array $slips, array $summary): string
    {
        $account = $this->qcId($company);

        if ($account === null) {
            throw new Rl1ExportBlockedException(__('Set your Revenu Québec identification number (10 digits) and file number (RS followed by 4 digits, for example RS0001) in Settings → Payroll. RL-1 slips are filed under that account, as shown on your source-deductions remittance form.'));
        }

        foreach ($slips as $slip) {
            if (! array_key_exists('boxBA', $slip)) {
                throw new Rl1ExportBlockedException(__('These RL-1 slips were finalized before LineLedger reported boxes B.A, B.B and C. Unlock and finalize them again to export them.'));
            }
        }

        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $transmission = $doc->createElement('Transmission');
        $doc->appendChild($transmission);

        $transmission->appendChild($this->transmitter($doc, $company, $year, count($slips)));

        $releves = $doc->createElement('RELEVES1');
        $transmission->appendChild($releves);

        $sequence = 1;

        foreach ($slips as $slip) {
            $releves->appendChild($this->slip($doc, $account, $year, $slip, $sequence++));
        }

        $releves->appendChild($this->summary($doc, $company, $account, $year, $summary, count($slips)));

        return (string) $doc->saveXML();
    }

    private function transmitter(DOMDocument $doc, Company $company, int $year, int $slipCount): DOMElement
    {
        $t = $doc->createElement('Transmetteur');
        $this->add($doc, $t, 'NoIdentification', (string) config('payroll.rl1.transmitter_number'));
        $this->add($doc, $t, 'NoPreparateur', (string) config('payroll.rl1.preparer_number'));
        $this->add($doc, $t, 'TypeReleve', (string) config('payroll.rl1.slip_type'));
        $this->add($doc, $t, 'AnneeImposition', (string) $year);
        $this->add($doc, $t, 'NbReleves', (string) $slipCount);

        $name = $doc->createElement('Nom');
        $this->add($doc, $name, 'Ligne1', $this->clean($company->name, 30));
        $t->appendChild($name);

        $addr = $doc->createElement('Adresse');
        $this->add($doc, $addr, 'Ligne1', $this->clean((string) $company->address_line1, 30));
        $this->add($doc, $addr, 'Ville', $this->clean((string) $company->address_city, 28));
        $this->add($doc, $addr, 'Province', (string) ($company->address_region ?: 'QC'));
        $this->add($doc, $addr, 'CodePostal', $this->postal((string) $company->address_postal_code));
        $t->appendChild($addr);

        return $t;
    }

    /**
     * @param  array{identification: string, file: string}  $account
     * @param  array<string, mixed>  $slip
     */
    private function slip(DOMDocument $doc, array $account, int $year, array $slip, int $sequence): DOMElement
    {
        $contact = Contact::query()->withoutGlobalScopes()->find($slip['contact_id']);
        $profile = EmployeePayrollProfile::query()->withoutGlobalScopes()
            ->where('contact_id', $slip['contact_id'])->first();

        $releve = $doc->createElement('Releve1');
        $this->add($doc, $releve, 'NoReleve', str_pad((string) $sequence, 9, '0', STR_PAD_LEFT));
        $this->add($doc, $releve, 'TypeReleve', (string) config('payroll.rl1.slip_type'));
        $this->add($doc, $releve, 'AnneeImposition', (string) $year);
        $this->add($doc, $releve, 'NoIdentificationQc', $account['identification']);
        $this->add($doc, $releve, 'NoDossier', $account['file']);

        $name = $doc->createElement('NomParticulier');
        $this->add($doc, $name, 'NomFamille', $this->clean((string) ($contact?->last_name ?: $slip['name']), 30));
        $this->add($doc, $name, 'Prenom', $this->clean((string) ($contact?->first_name ?? ''), 30));
        $releve->appendChild($name);

        $this->add($doc, $releve, 'NoAssuranceSociale', $this->sin($profile));

        // RL-1 boxes; B.A, B.B, C, E and H are left blank (out) when zero.
        $boxes = $doc->createElement('Montants');
        $this->money($doc, $boxes, 'CaseA', (int) $slip['boxA']); // Employment income
        $this->moneyIfAny($doc, $boxes, 'CaseBA', (int) $slip['boxBA']); // QPP: base + first additional
        $this->moneyIfAny($doc, $boxes, 'CaseBB', (int) $slip['boxBB']); // QPP: second additional
        $this->moneyIfAny($doc, $boxes, 'CaseC', (int) $slip['boxC']); // EI premium
        $this->moneyIfAny($doc, $boxes, 'CaseE', (int) $slip['boxE']); // Quebec income tax
        $this->money($doc, $boxes, 'CaseG', (int) $slip['boxG']); // QPP pensionable salary
        $this->moneyIfAny($doc, $boxes, 'CaseH', (int) $slip['boxH']); // QPIP premium
        $this->money($doc, $boxes, 'CaseI', (int) $slip['boxI']); // QPIP eligible salary
        $releve->appendChild($boxes);

        return $releve;
    }

    /**
     * @param  array{identification: string, file: string}  $account
     * @param  array<string, mixed>  $summary
     */
    private function summary(DOMDocument $doc, Company $company, array $account, int $year, array $summary, int $slipCount): DOMElement
    {
        $sommaire = $doc->createElement('Sommaire1');
        $this->add($doc, $sommaire, 'NoIdentificationQc', $account['identification']);
        $this->add($doc, $sommaire, 'NoDossier', $account['file']);
        $this->add($doc, $sommaire, 'AnneeImposition', (string) $year);
        $this->add($doc, $sommaire, 'NbReleves', (string) $slipCount);

        $name = $doc->createElement('NomEmployeur');
        $this->add($doc, $name, 'Ligne1', $this->clean($company->name, 30));
        $sommaire->appendChild($name);

        $tamt = $doc->createElement('MontantsTotaux');
        $this->money($doc, $tamt, 'TotalCaseA', (int) $summary['boxA']);
        $this->money($doc, $tamt, 'TotalCaseBA', (int) $summary['boxBA']);
        $this->money($doc, $tamt, 'TotalCaseBB', (int) $summary['boxBB']);
        $this->money($doc, $tamt, 'TotalCaseC', (int) $summary['boxC']);
        $this->money($doc, $tamt, 'TotalCaseE', (int) $summary['boxE']);
        $this->money($doc, $tamt, 'TotalCaseG', (int) $summary['boxG']);
        $this->money($doc, $tamt, 'TotalCaseH', (int) $summary['boxH']);
        $this->money($doc, $tamt, 'TotalCaseI', (int) $summary['boxI']);
        // RL-1 summary line 22 (base + first additional) and line 24 (second additional).
        $this->money($doc, $tamt, 'CotisationEmployeurRRQ', (int) $summary['employer_qpp']);
        $this->money($doc, $tamt, 'CotisationEmployeurRRQSupplementaire', (int) ($summary['employer_qpp2'] ?? 0));
        $this->money($doc, $tamt, 'CotisationEmployeurRQAP', (int) $summary['employer_qpip']);
        $this->money($doc, $tamt, 'CotisationFSS', (int) $summary['qhsf']);
        $sommaire->appendChild($tamt);

        // WSDRF (workforce skills development) reconciliation.
        $wsdrf = $doc->createElement('FormationMainDoeuvre');
        $this->add($doc, $wsdrf, 'Assujetti', $summary['wsdrf_applicable'] ? 'O' : 'N');
        $this->money($doc, $wsdrf, 'MasseSalariale', (int) $summary['wsdrf_payroll_cents']);
        $this->money($doc, $wsdrf, 'DepensesFormation', (int) $summary['wsdrf_training_cents']);
        $this->money($doc, $wsdrf, 'CotisationDue', (int) $summary['wsdrf_levy_cents']);
        $sommaire->appendChild($wsdrf);

        return $sommaire;
    }

    private function add(DOMDocument $doc, DOMElement $parent, string $name, string $value): void
    {
        $element = $doc->createElement($name);
        $element->appendChild($doc->createTextNode($value)); // escapes &, <, > correctly
        $parent->appendChild($element);
    }

    private function moneyIfAny(DOMDocument $doc, DOMElement $parent, string $name, int $cents): void
    {
        if ($cents !== 0) {
            $this->money($doc, $parent, $name, $cents);
        }
    }

    private function money(DOMDocument $doc, DOMElement $parent, string $name, int $cents): void
    {
        $parent->appendChild($doc->createElement($name, number_format($cents / 100, 2, '.', '')));
    }

    private function sin(?EmployeePayrollProfile $profile): string
    {
        $sin = $profile?->sin_encrypted; // decrypted by the cast

        return $sin !== null && $sin !== '' ? preg_replace('/\D/', '', $sin) : '000000000';
    }

    /**
     * The employer's Revenu Québec account from Settings → Payroll: the 10-digit
     * identification number and the source-deductions file number, RS and 4
     * digits (the 4 digits alone get the RS). Null until both are set.
     *
     * @return array{identification: string, file: string}|null
     */
    private function qcId(Company $company): ?array
    {
        $identification = preg_replace('/[\s-]+/', '', (string) $company->payroll_rq_identification_number) ?? '';
        $file = mb_strtoupper(preg_replace('/[\s-]+/', '', (string) $company->payroll_rq_file_number) ?? '');

        if (preg_match('/^\d{4}$/', $file) === 1) {
            $file = 'RS'.$file;
        }

        if (preg_match('/^\d{10}$/', $identification) !== 1 || preg_match('/^RS\d{4}$/', $file) !== 1) {
            return null;
        }

        return ['identification' => $identification, 'file' => $file];
    }

    private function clean(string $value, int $max): string
    {
        return mb_substr(trim($value), 0, $max);
    }

    private function postal(string $value): string
    {
        return mb_strtoupper(preg_replace('/\s+/', '', $value));
    }
}
