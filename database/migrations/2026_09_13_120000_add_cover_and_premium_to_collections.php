<?php

use App\Models\Collection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A cover image for a pack, and a count of how much of it is paid for.
 *
 * ── WHY premium_count AND NOT is_premium ─────────────────────────────────
 *
 * A pack is premium because of what is IN it. A switch on the pack would be
 * a second place where that fact lives, and second places go stale: move one
 * sound to premium and the badge keeps saying Free until somebody remembers
 * to flip it. Nobody remembers. The visitor finds out at the download.
 *
 * So it is counted, not declared — derived from the sounds and refreshed by
 * the same method that already keeps sounds_count honest. The column exists
 * only so a listing of twelve packs is one query instead of thirteen; it is
 * a cache of a truth that lives elsewhere, which is a very different thing
 * from a truth stored twice.
 *
 * ── WHY A PATH AND NOT THE media TABLE ───────────────────────────────────
 *
 * A pack has exactly one cover, for its whole life. The media table exists
 * for content where an unknown number of images are inserted into prose. A
 * relation for a one-to-one that is never queried on its own adds a join to
 * every listing in exchange for nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collections', function (Blueprint $table) {
            // Relative to the media disk — never a full URL. A stored URL
            // breaks the day the storage provider changes, and the whole
            // point of config('dbelo.storage.media') is that it can.
            $table->string('cover_path')->nullable()->after('description');

            $table->unsignedInteger('premium_count')->default(0)->after('sounds_count');
        });

        /*
         * Fill it in for what already exists.
         *
         * Without this every pack reads as entirely free until somebody edits
         * it — a new column defaulting to 0 is not "unknown", it is a claim,
         * and on day one it would be a wrong one.
         */
        Collection::query()->chunkById(100, function ($packs) {
            foreach ($packs as $pack) {
                $pack->updateQuietly([
                    'premium_count' => $pack->sounds()->where('is_premium', true)->count(),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('collections', function (Blueprint $table) {
            $table->dropColumn(['cover_path', 'premium_count']);
        });
    }
};
