<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            // straight_line | declining_balance | immediate. Every existing asset
            // keeps today's behaviour (straight-line) via the default.
            $table->string('depreciation_method', 20)->default('straight_line')->after('useful_life_months');

            // Annual percentage for declining_balance (20.000 = 20% a year).
            // Null for the other methods.
            $table->decimal('depreciation_rate', 6, 3)->nullable()->after('depreciation_method');

            // Declining balance with no useful life ends once the balance left would
            // be within this amount. Null means the default: 5% of cost.
            $table->bigInteger('materiality_limit_cents')->nullable()->after('depreciation_rate');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn(['depreciation_method', 'depreciation_rate', 'materiality_limit_cents']);
        });
    }
};
