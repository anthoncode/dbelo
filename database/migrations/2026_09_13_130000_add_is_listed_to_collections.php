<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The third state: listed in the public directory.
 *
 * ── WHY A SECOND BOOLEAN AND NOT ONE `visibility` COLUMN ─────────────────
 *
 * A single enum would read better, and it would also mean rewriting every
 * screen that touches is_public — and most of them are PACK screens, where
 * is_public means "live" and has nothing to do with anybody's privacy. A
 * rename that large, to express something packs do not have, buys clarity
 * in one place by risking six.
 *
 * So is_public keeps its meaning — CAN THIS BE OPENED BY SOMEBODY ELSE —
 * and is_listed answers a second, narrower question: DOES IT APPEAR IN THE
 * DIRECTORY. The pair has four combinations and only three are legal; the
 * fourth (private but listed) is impossible because nothing writes the
 * columns directly. Collection::setVisibility() is the only door, and it
 * writes both together. The model's visibility() reads them back as one
 * word, so every screen still deals in three states and never in booleans.
 *
 * ── WHY NOTHING IS BACKFILLED TO LISTED ──────────────────────────────────
 *
 * Everything already shared stays UNLISTED, and that is the whole point.
 * Those collections were shared under a button whose tooltip promised "it
 * is never listed or indexed". Backfilling them into a public directory
 * would publish, retroactively, pages people believed were link-only. The
 * default is false and nobody is moved: appearing in the directory is a
 * decision each owner makes after this exists, not one made for them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collections', function (Blueprint $table) {
            $table->boolean('is_listed')->default(false)->after('is_public');

            // The directory reads exactly this pair, in this order, and
            // nothing else narrows it. Without the index that page is a full
            // scan of every collection on the site to find the handful that
            // opted in.
            $table->index(['is_listed', 'is_public']);
        });
    }

    public function down(): void
    {
        Schema::table('collections', function (Blueprint $table) {
            $table->dropIndex(['is_listed', 'is_public']);
            $table->dropColumn('is_listed');
        });
    }
};
