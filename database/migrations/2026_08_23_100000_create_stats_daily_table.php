<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pre-computed daily numbers. The whole point of the Analytics screen.
     *
     * A dashboard that runs COUNT(*) over the downloads table on every page
     * load is fine with a thousand rows and unusable with ten million — and
     * it gets slower exactly as the site succeeds. Everything here is
     * summed once a day and then read as a handful of tiny rows.
     *
     * Two kinds of metric live in this table and they must not be confused:
     *
     *   DERIVED   downloads, signups, plays, subscriptions. Recomputed from
     *             the source tables by `stats:rollup`, so the command is
     *             safe to re-run and always self-corrects.
     *
     *   LIVE      visits, visitors, referrers, paths. Incremented by the
     *             middleware as they happen, with no source table behind
     *             them. The rollup must never touch these — recomputing
     *             would erase them.
     */
    public function up(): void
    {
        Schema::create('stats_daily', function (Blueprint $table) {
            $table->id();

            // The local date, not UTC. "Downloads today" has to mean the day
            // the operator is living in, not a window that ends at 8pm.
            $table->date('day');

            // downloads · downloads.category · visits · visits.referrer …
            // The prefix before the dot is the measure, the part after it is
            // the dimension the label belongs to.
            $table->string('metric', 40);

            // The dimension value. Empty string rather than NULL on purpose:
            // MySQL treats every NULL as distinct inside a unique index, so a
            // nullable column here would happily store the same row twice and
            // the upsert would silently stop working.
            $table->string('label', 120)->default('');

            $table->unsignedBigInteger('value')->default(0);

            $table->timestamps();

            $table->unique(['day', 'metric', 'label']);
            $table->index(['metric', 'day']);
        });

        // "Top downloads this month" groups the raw table by sound over a
        // date range. Without this index that is a full scan; with it, it is
        // a range read.
        Schema::table('downloads', function (Blueprint $table) {
            $table->index(['created_at', 'sound_id'], 'downloads_created_sound_index');
        });
    }

    public function down(): void
    {
        Schema::table('downloads', function (Blueprint $table) {
            $table->dropIndex('downloads_created_sound_index');
        });

        Schema::dropIfExists('stats_daily');
    }
};
