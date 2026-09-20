<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Does this page belong in the footer?
 *
 * ── WHAT WAS WRONG ───────────────────────────────────────────────────────
 *
 * Post::footerPages() took EVERY published page. There was no way to say no:
 * publishing a page put it in the footer of every page on the site, and the
 * only control was `sort_order`, which decides the order of a list nobody
 * could opt out of.
 *
 * That is fine for About and Contact and wrong for everything else a CMS is
 * for — a landing page for one campaign, a page written for a link in an
 * email, a thank-you page after a form. None of those wants to be in the
 * footer, and all of them were.
 *
 * ── WHY A COLUMN AND NOT A CONVENTION ────────────────────────────────────
 *
 * The cheap version of this is "sort_order greater than zero means show it".
 * It needs no migration and it is a trap: the field is labelled "Order in
 * the footer", so the number that means "first" would also be the number
 * that means "hidden", and 0 and 1 would differ by more than one position.
 * A control that quietly means two things is worse than a second control.
 *
 * ── WHY EXISTING PAGES ARE BACKFILLED TO TRUE ────────────────────────────
 *
 * The default is false — a new page is not in the footer until somebody
 * says so — but every page that exists RIGHT NOW was written under the old
 * rule, and is in the footer today. A migration that changes what the site
 * looks like is a migration that gets blamed for something else later. So:
 * new pages start out, old pages stay where they are, and the checkbox is
 * there to change either.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->boolean('in_footer')->default(false)->after('sort_order');
        });

        // Only pages. A blog post has never been in the footer and this
        // column means nothing on one — the footer links to the blog, not
        // to the posts in it.
        DB::table('posts')->where('type', 'page')->update(['in_footer' => true]);
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn('in_footer');
        });
    }
};
