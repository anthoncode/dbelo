<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Errors, grouped.
     *
     * One row per DISTINCT problem, not per occurrence. The same exception
     * thrown at the same line four thousand times is one thing that happened
     * four thousand times, and storing it four thousand times produces a
     * table nobody can read and a screen that is slower every day.
     *
     * The counters and the two dates are what make the screen useful:
     * "first seen ten minutes ago" is an alarm, and "first seen in March,
     * 40,000 times" is furniture. Those two need to look different at a
     * glance, and they cannot unless the grouping happens on write.
     */
    public function up(): void
    {
        Schema::create('error_groups', function (Blueprint $table) {
            $table->id();

            // sha1 of class + normalised message + the first frame that is
            // ours. See ErrorReporter::fingerprint().
            $table->char('fingerprint', 40)->unique();

            $table->string('level', 12)->default('error');   // error · critical
            $table->string('class');                          // exception class
            $table->text('message');
            $table->string('file')->nullable();               // relative to base_path
            $table->unsignedInteger('line')->nullable();

            $table->unsignedBigInteger('count')->default(0);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();

            // open · resolved · muted
            $table->string('status', 12)->default('open');
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();

            /*
             * The single most valuable signal an error tracker gives you.
             *
             * "Resolved" does not mean "hidden"; it means "tell me if this
             * happens again after now". When it does, this is stamped and the
             * group reopens — which is the tracker telling you your fix did
             * not fix it. Without it, resolving is just sweeping.
             */
            $table->timestamp('regressed_at')->nullable();

            // The most recent occurrence, for reproducing it. Deliberately
            // one sample rather than a history: the ten-thousandth copy of
            // the same stack trace has never helped anybody.
            $table->json('last_context')->nullable();
            $table->longText('last_trace')->nullable();

            $table->timestamps();

            $table->index(['status', 'last_seen_at']);
            $table->index('last_seen_at');
        });

        /*
         * Daily counts, so "is this getting worse?" has an answer.
         *
         * Its own table rather than a JSON blob on the group: the counter is
         * written from web requests and from the queue worker at the same
         * time, and read-modify-write on a JSON column loses increments the
         * moment two of them overlap.
         */
        Schema::create('error_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('error_group_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('count')->default(0);

            $table->unique(['error_group_id', 'date']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('error_daily');
        Schema::dropIfExists('error_groups');
    }
};
