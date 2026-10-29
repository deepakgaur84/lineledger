<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How much of each posted earning was paid out of the employee's accrued
 * dollar balance for its code: vacation pay taken. Posting settles that part
 * from the accrual's liability instead of expensing it again, and draws the
 * balance down by it; a void reads it back, so the balance returns by exactly
 * what posting took.
 *
 * Null until a run posts, and on every earning posted before this column
 * existed: voiding one of those reverses its draw by the rules it was posted
 * under.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pay_run_line_earnings', function (Blueprint $table): void {
            $table->bigInteger('accrual_relief_cents')->nullable()->after('amount_cents');
        });
    }

    public function down(): void
    {
        Schema::table('pay_run_line_earnings', function (Blueprint $table): void {
            $table->dropColumn('accrual_relief_cents');
        });
    }
};
