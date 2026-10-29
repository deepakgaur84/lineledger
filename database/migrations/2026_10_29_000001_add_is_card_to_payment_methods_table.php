<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marks the payment methods that are a card (credit, debit or prepaid) or a
     * payment app such as PayPal. The card company or app reports those payments
     * on Form 1099-K, so the US 1099 Summary leaves them out of 1099-NEC totals.
     *
     * Existing methods named like a card ("Credit card", "Debit card",
     * "Card (Stripe)") start marked; owners mark any other card or app method
     * (PayPal, Venmo) themselves.
     */
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->boolean('is_card')->default(false)->after('is_cheque');
        });

        DB::table('payment_methods')
            ->select(['id', 'name'])
            ->orderBy('id')
            ->chunkById(500, function ($methods): void {
                $cardIds = $methods
                    ->filter(fn (object $method): bool => str_contains(mb_strtolower((string) $method->name), 'card'))
                    ->pluck('id');

                if ($cardIds->isNotEmpty()) {
                    DB::table('payment_methods')->whereIn('id', $cardIds->all())->update(['is_card' => true]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropColumn('is_card');
        });
    }
};
