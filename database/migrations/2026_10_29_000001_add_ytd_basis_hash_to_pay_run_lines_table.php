<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pay_run_lines', function (Blueprint $table) {
            // Fingerprint of the year-to-date totals (CPP, CPP2, EI, QPP, QPP2,
            // QPIP and their bases) a line was calculated against. Posting
            // recomputes it and refuses a run whose YTD inputs have changed since,
            // so two runs can never both take the same remaining annual room.
            // Null means "calculated before this column existed": recalculate.
            $table->string('ytd_basis_hash', 64)->nullable()->after('ytd_tax_cents');
        });
    }

    public function down(): void
    {
        Schema::table('pay_run_lines', function (Blueprint $table) {
            $table->dropColumn('ytd_basis_hash');
        });
    }
};
