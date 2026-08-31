<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What visitors look for.
     *
     * In a sound bank this is the shopping list: the terms that come back
     * empty are exactly what to record or license next. Nothing else in the
     * admin tells you that with the same precision.
     *
     * Stored aggregated — one row per term, not one per search — so the
     * table stays small enough to query without thinking about it. The
     * per-day counts live next door, only so the trend column means
     * something: a term that spiked last week is not the same as one
     * searched once a year ago.
     */
    public function up(): void
    {
        Schema::create('searches', function (Blueprint $table) {
            $table->id();

            // Normalised: lowercase, trimmed, accents folded. "Puerta
            // Chirriando" and "puerta chirriando" are one need, not two rows.
            $table->string('term', 120);

            // What was actually typed the first time, kept for display.
            $table->string('raw', 160);

            // Hits the last time it was run. Zero is the headline number.
            $table->unsignedInteger('results')->default(0);

            $table->unsignedInteger('count')->default(1);

            // A search that returns results and gets no click is a different
            // failure from one that returns nothing: you HAVE it and you are
            // showing the wrong thing. That one is fixable today.
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('downloads')->default(0);

            // open | resolved | ignored. Lets the list be worked through
            // instead of growing forever.
            $table->string('status', 20)->default('open');
            $table->text('note')->nullable();

            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();

            $table->timestamps();

            $table->unique('term');
            $table->index(['results', 'count']);
            $table->index(['status', 'last_seen_at']);
        });

        Schema::create('search_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('search_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->unsignedInteger('count')->default(0);

            $table->unique(['search_id', 'day']);
            $table->index('day');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_daily');
        Schema::dropIfExists('searches');
    }
};
