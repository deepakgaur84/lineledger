<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One set of aggregator credentials per company and provider. The tokens
        // are encrypted by the model cast.
        Schema::create('bank_feed_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20)->default('akahu');
            $table->text('app_token');
            $table->text('user_token');
            $table->string('status', 20)->default('active');
            $table->text('last_error')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'provider']);
        });

        // One bank account at the provider, optionally linked to a ledger account.
        Schema::create('bank_feed_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bank_feed_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_id');
            $table->string('name');
            $table->string('institution')->nullable();
            $table->string('account_number')->nullable();
            $table->string('currency', 3)->nullable();
            $table->date('sync_from')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['bank_feed_connection_id', 'external_id']);
        });

        Schema::table('bank_statement_imports', function (Blueprint $table) {
            $table->foreignId('bank_feed_account_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bank_statement_imports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bank_feed_account_id');
        });

        Schema::dropIfExists('bank_feed_accounts');
        Schema::dropIfExists('bank_feed_connections');
    }
};
