<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Every attempt to sign in, successful or not.
         *
         * The one row-per-event table in this whole feature, and it earns it:
         * the questions people ask after a break-in are all "when", "from
         * where" and "how many times", and none of them can be answered from
         * a counter. It is also the only table here that could grow quickly,
         * so it is pruned on a schedule.
         *
         * The email is stored as TYPED, not resolved to a user. Somebody
         * guessing addresses that do not exist is exactly the pattern worth
         * seeing, and joining to users would erase it.
         */
        Schema::create('login_attempts', function (Blueprint $table) {
            $table->id();
            $table->string('email')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();

            // success · failed · lockout · two_factor
            $table->string('outcome', 20);
            $table->boolean('is_admin')->default(false);

            $table->timestamp('created_at')->nullable();

            $table->index(['ip_address', 'created_at']);
            $table->index(['email', 'created_at']);
            $table->index(['outcome', 'created_at']);
            $table->index('created_at');
        });

        /*
         * Blocked addresses.
         *
         * expires_at is nullable but a null is meant to be rare. An IP is not
         * a person: carrier-grade NAT puts thousands of phones behind one
         * address, offices and schools share one, and home connections rotate
         * theirs. A permanent block is therefore a permanent block of a
         * neighbourhood, most of whom were never involved — and of whoever
         * moves into that address next month.
         *
         * So automatic blocks always expire, and the screen makes the manual
         * permanent one the deliberate, awkward choice it should be.
         */
        Schema::create('ip_blocks', function (Blueprint $table) {
            $table->id();
            $table->string('ip_address', 45)->unique();
            $table->string('reason');
            $table->string('source', 10)->default('manual');   // manual · auto
            $table->unsignedInteger('hits')->default(0);       // requests refused since
            $table->timestamp('expires_at')->nullable();       // null = permanent
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('expires_at');
        });

        /*
         * Abuse, grouped — the same shape as error_groups, for the same
         * reason. Somebody enumerating the catalogue generates thousands of
         * requests; that is ONE thing happening, and a table with a row per
         * request is a table that helps nobody and fills the disk while doing
         * it.
         *
         * The fingerprint exists because the natural key contains nullable
         * columns, and MySQL lets a unique index hold any number of NULLs —
         * so a unique(kind, ip, user_id) would not group anything for the
         * signals that have no user.
         */
        Schema::create('abuse_signals', function (Blueprint $table) {
            $table->id();
            $table->char('fingerprint', 40)->unique();

            // rate · enumeration · shared_account · credential_stuffing · spray
            $table->string('kind', 24);
            $table->string('ip_address', 45)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('detail', 500)->nullable();
            $table->unsignedBigInteger('count')->default(0);
            $table->unsignedInteger('peak')->default(0);   // worst single window

            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();

            // open · reviewed · ignored
            $table->string('status', 10)->default('open');

            $table->timestamps();

            $table->index(['status', 'last_seen_at']);
            $table->index(['kind', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('abuse_signals');
        Schema::dropIfExists('ip_blocks');
        Schema::dropIfExists('login_attempts');
    }
};
