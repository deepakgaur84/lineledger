<?php

namespace App\Support\Reporting;

/**
 * One document's contribution to one revenue account, for a single sales rep —
 * a row of the Sales by Rep drill-down. Like {@see ComparisonRow}, a dedicated
 * object rather than an array shape keeps the report builder's Collection return
 * type clean under static analysis.
 */
final readonly class RepSalesRow
{
    public function __construct(
        public ?int $accountId,
        public string $accountCode,
        public string $accountName,
        public string $docType,
        public int $docId,
        public string $docNo,
        public string $docDate,
        public ?int $contactId,
        public string $contact,
        public int $amountCents,
        public string $routeName,
        public string $routeParam,
    ) {}

    /** "4950 — Consulting Revenue", or just the name for an unassigned account. */
    public function accountLabel(): string
    {
        return trim($this->accountCode.' — '.$this->accountName, ' —');
    }
}
