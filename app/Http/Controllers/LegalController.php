<?php

namespace App\Http\Controllers;

use App\Models\License;
use Illuminate\View\View;

/**
 * One legal page, not four.
 *
 * ── WHAT LEFT ────────────────────────────────────────────────────────────
 *
 * Terms, Privacy and the Contributor Agreement are CMS pages now: same
 * URLs, same route names, text edited in Admin → Pages. Their titles and
 * descriptions moved with them — meta_title and meta_description are
 * columns on the page, so the operator writes the search result too, which
 * is better than three strings hard-coded in a controller they will never
 * open.
 *
 * ── WHY THIS ONE STAYED ──────────────────────────────────────────────────
 *
 * Because /licenses is not prose and never was. It renders the licenses
 * table: every licence's name, version, summary and what it permits, from
 * the rows Admin → Licenses maintains. There is no body to edit.
 *
 * Seeding it as a page would have looked like consistency and behaved like
 * a bug: the text would freeze today's rows into a document, and the day a
 * licence changed, this page and the licence actually attached to each
 * sound would say different things with nothing to notice it. One fact,
 * one place — and the place is the table.
 *
 * The description below is still written here, for the same reason it was
 * before: "can I use this sound commercially" is a real search with real
 * intent, and this page is the answer to it.
 */
class LegalController extends Controller
{
    public function licenses(): View
    {
        view()->share('seo', [
            // The title people would actually type. "Licences" alone is what
            // the page is called; "what you can do with the sounds" is what
            // they are looking for.
            'title' => 'Sound effect licences — what you can use them for',
            'description' => 'Every dbelo licence in plain words: commercial use, attribution, YouTube and client work, '
                .'and the handful of things no licence here allows.',
        ]);

        return view('legal.licenses', [
            'licenses' => License::orderBy('id')->get(),
        ]);
    }
}
