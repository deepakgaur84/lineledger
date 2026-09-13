<?php

namespace App\Models;

use App\Services\EditLocks\EditLockManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A lease on one record being edited — see {@see EditLockManager}.
 *
 * Deliberately NOT BelongsToCompany: rows are only ever reached through an
 * already tenant-scoped record's (type, id), or by their secret token plus the
 * signed-in user's id from the heartbeat routes, which live outside the
 * {company} prefix (so nothing is bound for CompanyScope to use). `company_id`
 * is set explicitly from the locked record and exists for the FK cascade and
 * PurgeCompany. The manager writes through the query builder so every change
 * is a single compare-and-set statement.
 *
 * @property int $id
 * @property int $company_id
 * @property string $lockable_type
 * @property int $lockable_id
 * @property int|null $user_id
 * @property string|null $token
 * @property string $version
 * @property int|null $acquired_at_ms
 * @property int|null $last_active_at_ms
 * @property int|null $expires_at_ms
 * @property int|null $changed_at_ms
 */
class EditLock extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'lockable_id' => 'integer',
            'user_id' => 'integer',
            'acquired_at_ms' => 'integer',
            'last_active_at_ms' => 'integer',
            'expires_at_ms' => 'integer',
            'changed_at_ms' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
