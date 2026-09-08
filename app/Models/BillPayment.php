<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Concerns\GuardsPostedDeletion;
use App\Enums\BillPaymentStatus;
use App\Enums\BillType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'company_id', 'contact_id', 'payment_type', 'payment_no', 'payment_date',
    'paid_from_account_id', 'payment_method_id', 'reference',
    'amount_cents', 'currency_code', 'fx_rate', 'home_amount_cents', 'memo', 'status',
    'posted_at', 'posted_by_user_id', 'voided_at', 'voided_by_user_id',
    'journal_entry_id',
])]
class BillPayment extends Model
{
    use BelongsToCompany, GuardsPostedDeletion, SoftDeletes;

    /**
     * Where this payment was applied BEFORE SaveBillPayment last rewrote its
     * applications: the bill ids the previous rows pointed at, and the vendor
     * it belonged to. SaveBillPayment records them on the instance it returns;
     * BillPaymentPoster::repost() reads them so the bills the payment no
     * longer pays are recomputed too. Transient — never persisted.
     *
     * @var list<int>
     */
    protected array $previousApplicationBillIds = [];

    protected ?int $previousContactId = null;

    /**
     * @param  list<int>  $billIds
     */
    public function rememberPreviousApplications(array $billIds, ?int $contactId): static
    {
        $this->previousApplicationBillIds = array_values(array_unique(array_map('intval', $billIds)));
        $this->previousContactId = $contactId;

        return $this;
    }

    /**
     * @return list<int>
     */
    public function previousApplicationBillIds(): array
    {
        return $this->previousApplicationBillIds;
    }

    public function previousContactId(): ?int
    {
        return $this->previousContactId;
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function paidFromAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'paid_from_account_id');
    }

    /**
     * @return BelongsTo<PaymentMethod, $this>
     */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /**
     * @return HasMany<BillPaymentApplication, $this>
     */
    public function applications(): HasMany
    {
        return $this->hasMany(BillPaymentApplication::class);
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * Whether this payment is denominated in a foreign (non-home) currency.
     * Requires the company relation, which the poster loads before calling.
     */
    public function isForeignCurrency(): bool
    {
        return $this->currency_code !== null
            && ! $this->company->isHomeCurrency($this->currency_code);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payment_date' => 'date:Y-m-d',
            'payment_type' => BillType::class,
            'status' => BillPaymentStatus::class,
            'amount_cents' => 'integer',
            'home_amount_cents' => 'integer',
            'posted_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }
}
