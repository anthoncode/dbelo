<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Words that find a sound but are never shown on it.
 *
 * ── THE PROBLEM THIS EXISTS FOR ──────────────────────────────────────────
 *
 * The catalogue is in English. Somebody types "truenos" and gets nothing —
 * not "no results in your language", just nothing — and walks away believing
 * dbelo has no thunder. Meilisearch forgives a typo but it does not
 * translate, and "truenos" shares no root with "thunder" to forgive.
 *
 * ── WHY A COLUMN AND NOT MORE TAGS ───────────────────────────────────────
 *
 * Because they are not the same thing, and the moment they share a table
 * they start fighting over the same five slots on the sound's page.
 *
 * A tag is editorial: it is READ. Five of them, in one language, chosen so a
 * person scanning the page learns what the sound is.
 *
 * A search term is plumbing: it is MATCHED and never rendered. There can be
 * twenty, in any language, including the ugly ones nobody would put on a
 * page — misspellings, regionalisms, the word a Mexican editor uses and an
 * Argentine one does not.
 *
 * Keeping them apart is what lets the page stay narrow while the index stays
 * wide. Mixing them means either the page fills with duplicates in two
 * languages, or the index loses the words that make the catalogue findable.
 *
 * ── json AND NOT A PIVOT TABLE ───────────────────────────────────────────
 *
 * Nothing ever queries these in SQL. They are copied into Meilisearch by
 * toSearchableArray and matched there; the database only has to hand them
 * back with the row. A tags-style pivot would add a table, a model and two
 * joins to every load in exchange for a lookup nobody performs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sounds', function (Blueprint $table) {
            $table->json('search_terms')->nullable()->after('ai_provider');
        });
    }

    public function down(): void
    {
        Schema::table('sounds', function (Blueprint $table) {
            $table->dropColumn('search_terms');
        });
    }
};
