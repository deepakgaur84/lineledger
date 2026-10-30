<?php

namespace App\Services\Assets;

use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Bill;
use App\Models\BillLine;
use App\Models\Cheque;
use App\Models\ChequeLine;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalLine;

/**
 * Resolves an asset form's initial values from a source transaction line —
 * typically a Bill, Cheque, or Journal Entry line that hits a FixedAsset account.
 *
 * Returns the polymorphic source pair plus pre-fill data for the asset form.
 *
 * The line id comes from the form's query string, and the line tables carry no
 * company_id and no CompanyScope, so every lookup is confined to $company
 * through the line's parent document.
 */
class AssetSourcePrefiller
{
    /**
     * @return array{
     *     source_type: ?string,
     *     source_id: ?int,
     *     name: ?string,
     *     description: ?string,
     *     asset_account_id: ?int,
     *     acquired_date: ?string,
     *     cost_cents: ?int
     * }|null
     */
    public function resolve(Company $company, string $type, int $id): ?array
    {
        return match ($type) {
            'bill_line' => $this->fromBillLine($company, $id),
            'cheque_line' => $this->fromChequeLine($company, $id),
            'journal_line' => $this->fromJournalLine($company, $id),
            default => null,
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function fromBillLine(Company $company, int $id): ?array
    {
        $line = BillLine::query()
            ->whereHas('bill', fn ($q) => $q->where('company_id', $company->id))
            ->with(['bill', 'account'])
            ->find($id);

        if (! $line || ! $this->isFixedAssetAccount($company, $line->account_id)) {
            return null;
        }

        return [
            'source_type' => Bill::class,
            'source_id' => $line->bill_id,
            'name' => $line->description ?: ($line->bill->vendor_reference ?? null),
            'description' => $line->description,
            'asset_account_id' => (int) $line->account_id,
            'acquired_date' => $line->bill?->bill_date?->toDateString(),
            'cost_cents' => (int) $line->line_subtotal_cents,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function fromChequeLine(Company $company, int $id): ?array
    {
        $line = ChequeLine::query()
            ->whereHas('cheque', fn ($q) => $q->where('company_id', $company->id))
            ->with(['cheque', 'account'])
            ->find($id);

        if (! $line || ! $this->isFixedAssetAccount($company, $line->account_id)) {
            return null;
        }

        return [
            'source_type' => Cheque::class,
            'source_id' => $line->cheque_id,
            'name' => $line->description ?: ($line->cheque->payee_name ?? null),
            'description' => $line->description,
            'asset_account_id' => (int) $line->account_id,
            'acquired_date' => $line->cheque?->cheque_date?->toDateString(),
            'cost_cents' => (int) $line->amount_cents,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function fromJournalLine(Company $company, int $id): ?array
    {
        $line = JournalLine::query()
            ->whereHas('journalEntry', fn ($q) => $q->where('company_id', $company->id))
            ->with(['journalEntry', 'account'])
            ->find($id);

        if (! $line || ! $this->isFixedAssetAccount($company, $line->account_id)) {
            return null;
        }

        return [
            'source_type' => JournalEntry::class,
            'source_id' => $line->journal_entry_id,
            'name' => $line->memo,
            'description' => $line->memo,
            'asset_account_id' => (int) $line->account_id,
            'acquired_date' => $line->journalEntry?->entry_date?->toDateString(),
            'cost_cents' => (int) $line->debit_cents,
        ];
    }

    protected function isFixedAssetAccount(Company $company, ?int $accountId): bool
    {
        if (! $accountId) {
            return false;
        }

        $account = Account::query()->withoutGlobalScopes()->where('company_id', $company->id)->find($accountId);

        return $account && $account->subtype === AccountSubtype::FixedAsset;
    }
}
