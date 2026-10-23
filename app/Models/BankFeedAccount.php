<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One account at the provider, optionally linked to a ledger bank / credit-card
 * account. Only linked, active accounts are synced.
 */
#[Fillable([
    'company_id',
    'bank_feed_connection_id',
    'account_id',
    'external_id',
    'name',
    'institution',
    'account_number',
    'currency',
    'sync_from',
    'is_active',
    'last_synced_at',
    'last_error',
])]
class BankFeedAccount extends Model
{
    use BelongsToCompany;

    /**
     * @return BelongsTo<BankFeedConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(BankFeedConnection::class, 'bank_feed_connection_id');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function isLinked(): bool
    {
        return $this->account_id !== null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sync_from' => 'date:Y-m-d',
            'is_active' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }
}
