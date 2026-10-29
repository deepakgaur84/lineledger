<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('recurring_journal_entries', 'auto_post')) {
            return;
        }

        Schema::table('recurring_journal_entries', function (Blueprint $table): void {
            $table->boolean('auto_post')->default(false)->after('memo');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('recurring_journal_entries', 'auto_post')) {
            return;
        }

        Schema::table('recurring_journal_entries', function (Blueprint $table): void {
            $table->dropColumn('auto_post');
        });
    }
};
