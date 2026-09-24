<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Per-user: whether pressing Escape returns to the previous page
            // (resources/js/escape-back.js). On by default; anyone who finds it
            // gets in the way can switch it off under Settings → Appearance.
            $table->boolean('escape_goes_back')->default(true)->after('show_daily_insights');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('escape_goes_back');
        });
    }
};
