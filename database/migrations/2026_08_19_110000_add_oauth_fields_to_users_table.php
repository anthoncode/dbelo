<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // How the account proved it is real. An account created with
            // Google never receives a verification email, so without this
            // column those users look "unverified" forever in the admin —
            // which is exactly the kind of false alarm that makes an admin
            // stop trusting the column.
            $table->string('oauth_provider', 30)->nullable()->after('email_verified_at');
            $table->string('oauth_id')->nullable()->after('oauth_provider');

            // Two people can never hold the same Google account.
            $table->unique(['oauth_provider', 'oauth_id']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['oauth_provider', 'oauth_id']);
            $table->dropColumn(['oauth_provider', 'oauth_id']);
        });
    }
};
