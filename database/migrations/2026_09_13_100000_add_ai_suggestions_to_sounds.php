<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a model's suggestions wait for a human.
 *
 * ── THEY ARE NOT WRITTEN STRAIGHT INTO THE SOUND, AND THAT IS THE POINT ──
 *
 * Tags and a description are what the catalogue is searched and judged by.
 * A model that has only read a filename gets most of them right and some of
 * them confidently wrong, and the wrong ones do not look wrong — they read
 * exactly like the right ones. Writing them directly would mean the only way
 * to find a mistake is for somebody to search for a sound and get the wrong
 * one back.
 *
 * So they land here, in one json column, and Admin → In review is where they
 * become real. Accepting is an act, not a default.
 *
 * ── WHY A TIMESTAMP AND A PROVIDER, NOT JUST THE JSON ────────────────────
 *
 * ai_suggested_at makes the backfill resumable: re-running the command picks
 * up only what has none, so a run interrupted at sound 60 of 124 costs
 * nothing to continue. With fifty uploads a day and days away from the
 * machine, that property matters more than speed.
 *
 * ai_provider records who answered. The two drivers are meant to be compared
 * over the same sounds, and a comparison whose results cannot be told apart
 * afterwards is not a comparison.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sounds', function (Blueprint $table) {
            $table->json('ai_suggestions')->nullable()->after('meta_description');
            $table->timestamp('ai_suggested_at')->nullable()->after('ai_suggestions');
            $table->string('ai_provider', 20)->nullable()->after('ai_suggested_at');

            /*
             * The name the file arrived with.
             *
             * Nothing kept it until now: uploads are stored under a UUID and
             * FilenameMeta tidied the name into a title, which is then edited
             * by hand. The raw string is strictly more informative than the
             * tidied one — "FOOTBALL_STADIUM_CROWD_CHEER_44k_INT.wav" says
             * things the title no longer does — and it is the single best
             * input this whole feature has.
             *
             * Nullable, and it stays null for everything already imported.
             * Backfilling it is impossible; the name is gone.
             */
            $table->string('original_filename')->nullable()->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('sounds', function (Blueprint $table) {
            $table->dropColumn([
                'ai_suggestions',
                'ai_suggested_at',
                'ai_provider',
                'original_filename',
            ]);
        });
    }
};
