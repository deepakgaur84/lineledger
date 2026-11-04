<?php

namespace App\Services\Banking;

use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\BankStatementLine;
use App\Models\Company;
use Illuminate\Support\Collection;

/**
 * Finds the two legs of an inter-account transfer hiding in the review feed: an
 * outflow on one bank/credit-card account and a matching inflow on another, of
 * equal magnitude, same currency, within a few days. Each leg is paired at most
 * once, closest-dated first. Cross-currency movements (unequal magnitudes) and
 * same-account pairs are ignored.
 *
 * Fork: this runs on every For Review render, so it reads only four columns,
 * buckets the inflows by amount (an outflow only ever meets the inflows of the
 * same amount instead of every inflow in the queue) and hydrates models for the
 * pairs it keeps. It used to load every waiting line and compare each outflow
 * with all of them, which made a long queue — and every click on the page — slow.
 */
class TransferPairMatcher
{
    private const WINDOW_DAYS = 3;

    /**
     * @return Collection<int, array{out: BankStatementLine, in: BankStatementLine, days: int}>
     */
    public function candidates(Company $company): Collection
    {
        /** @var Collection<int, Account> $accounts */
        $accounts = Account::query()
            ->whereIn('subtype', [AccountSubtype::Bank->value, AccountSubtype::CreditCard->value])
            ->get(['id', 'code', 'name', 'currency_code', 'subtype'])
            ->keyBy('id');

        if ($accounts->isEmpty()) {
            return collect();
        }

        $rows = BankStatementLine::query()
            ->forReview()
            ->whereIn('account_id', $accounts->keys()->all())
            ->orderBy('txn_date')
            ->orderBy('id')
            ->toBase()
            ->get(['id', 'account_id', 'txn_date', 'amount_cents']);

        $currency = fn (int $accountId): string => (string) ($accounts->get($accountId)->currency_code ?? '');
        $day = fn (mixed $date): int => intdiv((int) strtotime(substr((string) $date, 0, 10).' UTC'), 86400);

        /** @var array<int, list<object>> $insByAmount */
        $insByAmount = [];
        $outs = [];

        foreach ($rows as $row) {
            $amount = (int) $row->amount_cents;

            if ($amount > 0) {
                $insByAmount[$amount][] = $row;
            } elseif ($amount < 0) {
                $outs[] = $row;
            }
        }

        $used = [];
        $pairs = [];

        foreach ($outs as $out) {
            $bucket = $insByAmount[-(int) $out->amount_cents] ?? null;

            if ($bucket === null) {
                continue;
            }

            $outDay = $day($out->txn_date);
            $outCurrency = $currency((int) $out->account_id);
            $best = null;
            $bestDiff = PHP_INT_MAX;

            foreach ($bucket as $in) {
                if (isset($used[$in->id]) || (int) $in->account_id === (int) $out->account_id || $currency((int) $in->account_id) !== $outCurrency) {
                    continue;
                }

                $diff = abs($day($in->txn_date) - $outDay);

                // Closest-dated first; on a tie the earlier line (date, then id) wins.
                if ($diff <= self::WINDOW_DAYS && $diff < $bestDiff) {
                    $best = $in;
                    $bestDiff = $diff;
                }
            }

            if ($best !== null) {
                $used[$best->id] = true;
                $pairs[] = [(int) $out->id, (int) $best->id, $bestDiff];
            }
        }

        if ($pairs === []) {
            return collect();
        }

        $ids = array_merge(array_column($pairs, 0), array_column($pairs, 1));
        $lines = BankStatementLine::query()->whereIn('id', $ids)->get()->keyBy('id');

        foreach ($lines as $line) {
            $line->setRelation('account', $accounts->get((int) $line->account_id));
        }

        $result = collect();

        foreach ($pairs as [$outId, $inId, $days]) {
            $result->push(['out' => $lines->get($outId), 'in' => $lines->get($inId), 'days' => $days]);
        }

        return $result;
    }
}
