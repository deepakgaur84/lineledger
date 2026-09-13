<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The address a cheque is mailed to, snapshotted on the cheque rather than read
 * live off the payee contact. A cheque is a physical document: reprinting one
 * from two years ago must show the address it actually went to, and the operator
 * can override the address on file for a single cheque (a care-of address, an
 * estate, a payee who has since moved) without touching the contact.
 *
 * Same reasoning — and the same column shape — as donation_receipts.donor_*.
 * Every other document reads the contact's address live.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cheques', function (Blueprint $table) {
            $table->string('payee_line1')->nullable()->after('payee_name');
            $table->string('payee_line2')->nullable()->after('payee_line1');
            $table->string('payee_city')->nullable()->after('payee_line2');
            $table->string('payee_region')->nullable()->after('payee_city');
            $table->string('payee_postal_code')->nullable()->after('payee_region');
            // Two-letter code, matching contacts.billing_country.
            $table->string('payee_country', 2)->nullable()->after('payee_postal_code');
        });
    }

    public function down(): void
    {
        Schema::table('cheques', function (Blueprint $table) {
            $table->dropColumn([
                'payee_line1', 'payee_line2', 'payee_city',
                'payee_region', 'payee_postal_code', 'payee_country',
            ]);
        });
    }
};
