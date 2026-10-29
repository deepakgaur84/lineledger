<?php

namespace App\Exceptions\Payroll;

use RuntimeException;

/**
 * Thrown when no loaded payroll tax table covers a pay date or province: before
 * the first table, or on or after January 1 of a year whose tables are not
 * loaded yet. The pay-run pages catch it and block the run with
 * {@see userMessage()} rather than silently computing with stale or missing
 * constants; getMessage() is the English detail for logs and the console.
 */
class MissingPayrollConstantsException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $payDate,
        public readonly ?string $province = null,
        public readonly ?string $coveredThrough = null,
    ) {
        parent::__construct($message);
    }

    /**
     * No federal table covers the pay date. $coveredThrough is the last date the
     * loaded federal tables cover.
     */
    public static function forDate(string $payDate, ?string $coveredThrough = null): self
    {
        return new self(
            "No federal payroll tax table is loaded for {$payDate}".self::coverageNote($payDate, $coveredThrough).'. Load the CRA T4127 constants for that period before running payroll.',
            $payDate,
            null,
            $coveredThrough,
        );
    }

    /**
     * None of the province's tables covers the pay date. $coveredThrough is the
     * last date its loaded tables cover (null when the province isn't loaded).
     */
    public static function forProvince(string $province, string $payDate, ?string $coveredThrough = null): self
    {
        return new self(
            "No payroll tax table is loaded for province {$province} effective {$payDate}".self::coverageNote($payDate, $coveredThrough).". Load that province's CRA T4127 constants before running payroll.",
            $payDate,
            $province,
            $coveredThrough,
        );
    }

    /**
     * Whether the pay date falls after the last day the loaded tables cover,
     * i.e. the tables for it are not installed yet.
     */
    public function isPastCoverage(): bool
    {
        return $this->coveredThrough !== null && $this->payDate > $this->coveredThrough;
    }

    /**
     * Why the payroll can't be run, for the person running it, in their language.
     */
    public function userMessage(): string
    {
        if ($this->isPastCoverage()) {
            return __('Payroll tax tables for :date aren’t installed yet. Pay dates after :through can be calculated once LineLedger is updated with the new tables.', [
                'date' => $this->payDate,
                'through' => $this->coveredThrough,
            ]);
        }

        return __('LineLedger has no payroll tax tables for :date.', ['date' => $this->payDate]);
    }

    private static function coverageNote(string $payDate, ?string $coveredThrough): string
    {
        return $coveredThrough !== null && $payDate > $coveredThrough
            ? " (the loaded tables cover pay dates through {$coveredThrough})"
            : '';
    }
}
