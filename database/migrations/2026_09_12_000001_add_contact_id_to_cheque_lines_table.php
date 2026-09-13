<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The customer or vendor a cheque line belongs to, for lines coded to the
 * Accounts Receivable / Accounts Payable control accounts. The payee on the
 * cheque header is who the money goes to; this is whose sub-ledger moves, and
 * the two are often different (refunding a policy beneficiary on a customer's
 * account, say). ChequePoster stamps it onto the GL leg, which is what the AR/AP
 * aging, contact statements and cached contact balances read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cheque_lines', function (Blueprint $table) {
            $table->foreignId('contact_id')->nullable()->after('account_id')->constrained('contacts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cheque_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('contact_id');
        });
    }
};
