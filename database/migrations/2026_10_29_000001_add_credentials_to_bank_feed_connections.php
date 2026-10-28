<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Provider-specific credentials beyond Akahu's two tokens (for Actual
        // Budget: server address, password, budget id). Encrypted by the model cast.
        Schema::table('bank_feed_connections', function (Blueprint $table) {
            $table->text('credentials')->nullable()->after('user_token');
        });
    }

    public function down(): void
    {
        Schema::table('bank_feed_connections', function (Blueprint $table) {
            $table->dropColumn('credentials');
        });
    }
};
