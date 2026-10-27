<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            // Fork: a default class and location per account. Applied to journal
            // lines posted without one (see JournalPoster), and — for a bank or
            // credit card account — inherited by the income / expense lines of the
            // same entry, so every transaction through that account is tagged.
            $table->foreignId('default_class_id')->nullable()->constrained('classifications')->nullOnDelete();
            $table->foreignId('default_location_id')->nullable()->constrained('locations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropForeign(['default_class_id']);
            $table->dropForeign(['default_location_id']);
            $table->dropColumn(['default_class_id', 'default_location_id']);
        });
    }
};
