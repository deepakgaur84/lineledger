<?php

namespace App\Services\Reporting\Cra;

use App\Support\Money;
use DOMDocument;
use DOMElement;
use Illuminate\Support\Str;

/**
 * Builds a CRA information-return XML file for Internet File Transfer: the
 * <Submission> root, its T619 electronic transmittal (2027V1 layout) and the
 * return the generators fill in. It applies the rules every CRA XML
 * specification shares, so the generators only map boxes:
 *
 *  - no empty tags: an optional field without a value is left out (the CRA
 *    rejects the whole file over a blank tag);
 *  - amounts in dollars and cents, exactly two decimals, no sign or separator;
 *  - names and addresses trimmed and cut to the specifications' lengths.
 */
final class CraXml
{
    private DOMDocument $doc;

    public function __construct()
    {
        $this->doc = new DOMDocument('1.0', 'UTF-8');
        $this->doc->formatOutput = true;
    }

    /**
     * The <Submission> root and its T619 for one return. Returns the <Return>
     * element the slips and summary go in.
     */
    public function submission(CraFiler $filer): DOMElement
    {
        $submission = $this->doc->createElement('Submission');
        $submission->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $this->doc->appendChild($submission);

        $t619 = $this->child($submission, 'T619');
        $this->text($this->child($t619, 'TransmitterAccountNumber'), 'bn15', $filer->accountOrPlaceholder());

        if ($filer->repId !== null) {
            $this->text($this->child($t619, 'TransmitterRepID'), 'RepID', $filer->repId);
        }

        $this->text($t619, 'sbmt_ref_id', self::submissionReference());
        $this->text($t619, 'summ_cnt', '1');
        $this->text($t619, 'lang_cd', $filer->languageCode);
        $this->text($this->child($t619, 'TransmitterName'), 'l1_nm', self::fit($filer->name, 35));
        $this->text($t619, 'TransmitterCountryCode', 'CAN');

        $contact = $this->child($t619, 'CNTC');
        $this->text($contact, 'cntc_nm', self::fit($filer->contactName, 35));
        $this->text($contact, 'cntc_area_cd', $filer->contactPhone?->areaCode);
        $this->text($contact, 'cntc_phn_nbr', $filer->contactPhone?->number);
        $this->text($contact, 'cntc_extn_nbr', $filer->contactPhone?->extension);
        $this->text($contact, 'cntc_email_area', $filer->contactEmail);

        return $this->child($submission, 'Return');
    }

    public function child(DOMElement $parent, string $name): DOMElement
    {
        $element = $this->doc->createElement($name);
        $parent->appendChild($element);

        return $element;
    }

    /** A text element; left out when there is no value. */
    public function text(DOMElement $parent, string $name, ?string $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $element = $this->doc->createElement($name);
        $element->appendChild($this->doc->createTextNode($value)); // escapes &, <, >
        $parent->appendChild($element);
    }

    /**
     * A dollars-and-cents amount. The CRA accepts no negative amounts; one
     * reaches here only in a file readiness() refuses, and is written as zero.
     */
    public function amount(DOMElement $parent, string $name, int $cents): void
    {
        $this->text($parent, $name, self::formatAmount(max(0, $cents)));
    }

    /** A name over the l1_nm / l2_nm lines of a name block, 30 characters each. */
    public function nameLines(DOMElement $parent, string $tag, string $name): void
    {
        $block = $this->child($parent, $tag);
        $name = self::fit($name, 100) ?? '';

        if (mb_strlen($name) <= 30) {
            $this->text($block, 'l1_nm', $name);

            return;
        }

        $break = mb_strrpos(mb_substr($name, 0, 31), ' ');
        $break = $break === false || $break === 0 ? 30 : $break;

        $this->text($block, 'l1_nm', self::fit(mb_substr($name, 0, $break), 30));
        $this->text($block, 'l2_nm', self::fit(mb_substr($name, $break), 30));
    }

    /** An address block (EMPE_ADDR, EMPR_ADDR, RCPNT_ADDR, PAYR_ADDR); left out when empty. */
    public function address(DOMElement $parent, string $tag, CraAddress $address): void
    {
        if ($address->isEmpty()) {
            return;
        }

        $block = $this->child($parent, $tag);
        $this->text($block, 'addr_l1_txt', $address->line1);
        $this->text($block, 'addr_l2_txt', $address->line2);
        $this->text($block, 'cty_nm', $address->city);
        $this->text($block, 'prov_cd', $address->province);
        $this->text($block, 'cntry_cd', $address->country);
        $this->text($block, 'pstl_cd', $address->postalCode);
    }

    /** The summary's CNTC block: contact name (22 characters), area code, number and extension. */
    public function summaryContact(DOMElement $parent, CraFiler $filer): void
    {
        $contact = $this->child($parent, 'CNTC');
        $this->text($contact, 'cntc_nm', self::fit($filer->contactName, 22));
        $this->text($contact, 'cntc_area_cd', $filer->contactPhone?->areaCode);
        $this->text($contact, 'cntc_phn_nbr', $filer->contactPhone?->number);
        $this->text($contact, 'cntc_extn_nbr', $filer->contactPhone?->extension);
    }

    public function toXml(): string
    {
        return (string) $this->doc->saveXML();
    }

    /** "1234.56": whole dollars, a point and two decimals (integer math, never floats). */
    public static function formatAmount(int $cents): string
    {
        return Money::fromCents($cents)->toDecimalString();
    }

    /** Whether $cents fits a field of $digits whole-dollar digits (a "decimal6" field holds 4). */
    public static function fits(int $cents, int $digits): bool
    {
        return intdiv(abs($cents), 100) < 10 ** $digits;
    }

    /**
     * Trimmed, inner whitespace collapsed and typographic apostrophes
     * straightened (the CRA keeps only the plain one), cut to $length; null
     * when nothing is left.
     */
    public static function fit(?string $value, int $length): ?string
    {
        $text = preg_replace('/\s+/u', ' ', str_replace(['’', '‘'], "'", trim((string) $value))) ?? '';
        $text = trim(mb_substr($text, 0, $length));

        return $text !== '' ? $text : null;
    }

    /**
     * The T619 submission reference: up to 8 letters and digits (no space or
     * hyphen), unique to each file the transmitter sends.
     */
    public static function submissionReference(): string
    {
        return Str::upper(Str::random(8));
    }
}
