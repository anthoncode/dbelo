<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How many times a sound page has been looked at.
     *
     * stats_daily already records traffic per PATH, but it deliberately
     * collapses every sound to "/sounds/:sound" — that is what keeps it at a
     * few rows a day instead of one per sound per day. Which means the one
     * question SEO actually asks about a catalogue — "which of these does
     * nobody ever look at?" — cannot be answered from it, by design.
     *
     * A counter on the row answers it in a single column, and doubles as the
     * popularity signal the catalogue currently lacks: today it can only
     * order by downloads, which ignores everyone who listened and moved on.
     */
    public function up(): void
    {
        Schema::table('sounds', function (Blueprint $table) {
            $table->unsignedInteger('views_count')->default(0)->after('downloads_count');

            // For "least viewed" and "never viewed", which is the whole point.
            $table->index('views_count');
        });
    }

    public function down(): void
    {
        Schema::table('sounds', function (Blueprint $table) {
            $table->dropIndex(['views_count']);
            $table->dropColumn('views_count');
        });
    }
};
