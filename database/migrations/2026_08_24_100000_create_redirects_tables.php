<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two tables that are really one workflow: what broke, and what we did
 * about it.
 *
 * `not_founds` is the inbox — every URL somebody asked for and did not get.
 * `redirects` is the reply. The screen that reads them puts one button
 * between the two, because a report you cannot act on is just a longer way
 * of feeling bad.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('redirects', function (Blueprint $table) {
            $table->id();

            // 500 chars, not 2048: MySQL caps a utf8mb4 index at 3072 bytes,
            // and `from` has to be unique or the map stops being a map.
            // No real URL on this site comes close.
            $table->string('from', 500)->unique();

            // Null when status is 410: "this is gone" has no destination.
            $table->string('to', 500)->nullable();

            // 301 permanent · 302 temporary · 410 gone.
            $table->unsignedSmallInteger('status')->default(301);

            // `from` ends in /* and `to` may contain * to receive the tail.
            // Stored as a column rather than inferred, so the wildcard list
            // can be fetched without scanning every row.
            $table->boolean('is_wildcard')->default(false);

            $table->unsignedBigInteger('hits')->default(0);
            $table->timestamp('last_hit_at')->nullable();

            // manual · claim · post · sound — where this rule came from.
            // The ones the site wrote for itself are worth telling apart from
            // the ones a person typed, because only the second kind is a
            // decision somebody has to stand behind.
            $table->string('source', 20)->default('manual');
            $table->string('note', 300)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // The wildcard list is read on every miss; keep it cheap.
            $table->index(['is_wildcard', 'id']);
        });

        Schema::create('not_founds', function (Blueprint $table) {
            $table->id();

            // Aggregated per path, never one row per hit. A single scanner
            // can ask for /wp-login.php ten thousand times in an afternoon,
            // and the version of this table that logs each one is a table
            // nobody can open six months from now.
            $table->string('path', 500)->unique();
            $table->unsignedBigInteger('hits')->default(0);

            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();

            // The last one seen, not all of them: what matters is whether
            // anything links here, and one example answers that.
            $table->string('referrer', 500)->nullable();
            $table->string('user_agent', 255)->nullable();

            // open · fixed · ignored · noise
            //
            // "noise" is set automatically for the WordPress and .env probes
            // that every site on the internet receives. They are still
            // counted — the volume is worth seeing once — but they never
            // appear in the list you are meant to work through.
            $table->string('status', 10)->default('open');

            $table->foreignId('redirect_id')->nullable()->constrained('redirects')->nullOnDelete();

            // The list is "worst first", and the default view is one status.
            $table->index(['status', 'hits']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('not_founds');
        Schema::dropIfExists('redirects');
    }
};
