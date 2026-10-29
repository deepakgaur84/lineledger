<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * All of a pay-run line's insurable earnings, before the EI maximum: the Record
 * of Employment reports every insurable dollar per pay period (Blocks 15B and
 * 15C), while `ei_insurable_cents` stays capped at the maximum insurable earnings
 * for the EI premium and T4 box 24.
 *
 * Null on lines calculated before this column existed; the ROE then adds up the
 * line's insurable earning rows instead. No backfill is needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pay_run_lines', function (Blueprint $table): void {
            $table->bigInteger('roe_insurable_earnings_cents')->nullable()->after('ei_insurable_cents');
        });
    }

    public function down(): void
    {
        Schema::table('pay_run_lines', function (Blueprint $table): void {
            $table->dropColumn('roe_insurable_earnings_cents');
        });
    }
};
