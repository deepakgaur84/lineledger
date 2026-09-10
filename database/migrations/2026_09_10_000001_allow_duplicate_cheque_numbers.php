<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cheque numbers repeat in real books. Anything paid without a physical cheque
 * still needs a label in the Cheque # column — "DD", "EFT", "e-transfer" — and
 * an operator uses the same one every time. The unique index turned that into a
 * hard database error, so it comes off; the cheque form now shows a dismissible
 * warning instead, which still catches a genuinely mis-keyed number.
 *
 * The composite index stays (non-unique): it backs the number lookups and, on
 * MySQL, keeps a leftmost `company_id` index available to the foreign key. It's
 * added before the unique is dropped for exactly that reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cheques', function (Blueprint $table) {
            $table->index(['company_id', 'bank_account_id', 'cheque_no'], 'cheques_company_bank_no_index');
            $table->dropUnique('cheques_company_id_bank_account_id_cheque_no_unique');
        });
    }

    public function down(): void
    {
        Schema::table('cheques', function (Blueprint $table) {
            $table->unique(['company_id', 'bank_account_id', 'cheque_no'], 'cheques_company_id_bank_account_id_cheque_no_unique');
            $table->dropIndex('cheques_company_bank_no_index');
        });
    }
};
