<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('form_styles', function (Blueprint $table) {
            // Invoice designer: which layout, typeface, logo placement and a
            // few switches. Defaults reproduce the pre-designer look exactly.
            $table->string('layout')->default('classic')->after('name');
            $table->string('font_family')->default('sans')->after('accent_color');
            $table->string('logo_position')->default('left')->after('show_logo');
            $table->unsignedSmallInteger('logo_max_height')->nullable()->after('logo_position');
            $table->string('title_text', 40)->nullable()->after('logo_max_height');
            $table->boolean('show_ship_to')->default(true)->after('title_text');
            $table->boolean('zebra_rows')->default(false)->after('show_ship_to');
        });
    }

    public function down(): void
    {
        Schema::table('form_styles', function (Blueprint $table) {
            $table->dropColumn(['template', 'font_family', 'logo_position', 'logo_max_height', 'title_text', 'show_ship_to', 'zebra_rows']);
        });
    }
};
