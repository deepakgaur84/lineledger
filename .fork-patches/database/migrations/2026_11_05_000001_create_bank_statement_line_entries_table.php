<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Fork: a statement line split across a category and a bill payment is
        // matched to more than one bank leg. The line keeps its first entry in
        // created_journal_entry_id / matched_journal_line_id; any further entry
        // is listed here.
        Schema::create('bank_statement_line_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_statement_line_id')->constrained('bank_statement_lines')->cascadeOnDelete();
            $table->foreignId('journal_entry_id')->constrained('journal_entries')->cascadeOnDelete();
            $table->foreignId('journal_line_id')->constrained('journal_lines')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['bank_statement_line_id', 'journal_entry_id'], 'bsl_entries_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_line_entries');
    }
};
