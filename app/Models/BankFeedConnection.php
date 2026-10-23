<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A company's credentials for a bank-data aggregator (Akahu). The two tokens are
 * encrypted at rest and never serialized.
 *
 * @property string $app_token
 * @property string $user_token
 */
#[Fillable([
    'company_id',
    'provider',
    'app_token',
    'user_token',
    'status',
    'last_error',
    'last_synced_at',
])]
#[Hidden(['app_token', 'user_token'])]
class BankFeedConnection extends Model
{
    use BelongsToCompany;

    /**
     * @return HasMany<BankFeedAccount, $this>
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(BankFeedAccount::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'app_token' => 'encrypted',
            'user_token' => 'encrypted',
            'last_synced_at' => 'datetime',
        ];
    }
}
