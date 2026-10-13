<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Per-user display format for dates (App\Enums\DateFormat). The
            // default is 01-Apr-26; see Settings → Appearance.
            $table->string('date_format')->default('dd_mmm_yy')->after('escape_goes_back');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('date_format');
        });
    }
};
