<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_categories', function (Blueprint $table) {
            // The method new assets in the category start with. Every existing category
            // stays straight-line via the default.
            $table->string('default_depreciation_method', 20)->default('straight_line')->after('default_useful_life_months');

            // Annual percentage for declining_balance (20.000 = 20% a year). Null otherwise.
            $table->decimal('default_depreciation_rate', 6, 3)->nullable()->after('default_depreciation_method');
        });
    }

    public function down(): void
    {
        Schema::table('asset_categories', function (Blueprint $table) {
            $table->dropColumn(['default_depreciation_method', 'default_depreciation_rate']);
        });
    }
};
