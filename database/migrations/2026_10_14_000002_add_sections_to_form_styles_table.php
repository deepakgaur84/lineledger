<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('form_styles', function (Blueprint $table) {
            // Ordered list of {key, visible} for the designed layouts' body
            // sections. Null means the default order with everything shown.
            $table->json('sections')->nullable()->after('zebra_rows');
        });
    }

    public function down(): void
    {
        Schema::table('form_styles', function (Blueprint $table) {
            $table->dropColumn('sections');
        });
    }
};
