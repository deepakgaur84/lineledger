<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per record that has ever been opened for editing. The row is
        // kept when the lease is released so its `version` survives: a save is
        // accepted only if the version still matches the one the editor got.
        //
        // Lease times are epoch milliseconds in plain integers (like Laravel's
        // own cache_locks.expiration) so comparisons behave identically on
        // MySQL and SQLite and carry the precision the load-race check needs.
        //
        // Ephemeral: excluded from company backups (BackupTableRegistry).
        Schema::create('edit_locks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('lockable_type');
            $table->unsignedBigInteger('lockable_id');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('token', 40)->nullable()->unique();
            $table->string('version', 20);
            $table->unsignedBigInteger('acquired_at_ms')->nullable();
            $table->unsignedBigInteger('last_active_at_ms')->nullable();
            $table->unsignedBigInteger('expires_at_ms')->nullable()->index();
            $table->unsignedBigInteger('changed_at_ms')->nullable();

            $table->unique(['lockable_type', 'lockable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edit_locks');
    }
};
