<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Suspended keeps every record intact: the account simply cannot
            // sign in or download. Deleting a user would orphan their
            // downloads, and those are the proof that licences were granted.
            $table->string('status', 20)->default('active')->after('role');
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspension_reason')->nullable();

            // Tells a live account apart from one that registered and never
            // came back — impossible to see from created_at alone.
            $table->timestamp('last_seen_at')->nullable();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['status', 'created_at']);
            $table->dropColumn(['status', 'suspended_at', 'suspension_reason', 'last_seen_at']);
        });
    }
};
