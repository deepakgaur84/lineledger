<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which invoice layout a form style prints with. Existing styles keep the
     * classic layout they have always had.
     *
     * Fork: the invoice designer's own migration
     * (2026_10_14_000001_add_design_columns_to_form_styles_table) already adds
     * this column, so adding it again must be skipped or the migration fails
     * with "Duplicate column name 'layout'".
     */
    public function up(): void
    {
        if (Schema::hasColumn('form_styles', 'layout')) {
            return;
        }

        Schema::table('form_styles', function (Blueprint $table) {
            $table->string('layout', 20)->default('classic')->after('name');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('form_styles', 'layout')) {
            return;
        }

        Schema::table('form_styles', function (Blueprint $table) {
            $table->dropColumn('layout');
        });
    }
};
