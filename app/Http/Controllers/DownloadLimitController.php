<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\Sound;
use App\Support\Downloads;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The page somebody reaches when the file did not come.
 *
 * This is the highest-intent moment on the site — a person who wants a
 * specific sound, right now — and it used to be abort(429): a grey page
 * with one sentence on it and nowhere to go. Whatever the conversion rate
 * of this screen turns out to be, it cannot be lower than that.
 *
 * IT COMPUTES ITS OWN STATE rather than reading a flashed message. A flash
 * is gone on refresh, cannot be linked to, and cannot be opened in a second
 * tab — three ways for the page to arrive empty at exactly the wrong
 * moment. The reason in the URL only chooses the wording; the numbers come
 * from the cookie and the plan, so they are right however you got here.
 */
class DownloadLimitController extends Controller
{
    /** The reasons this page knows how to explain. Anything else is generic. */
    private const REASONS = ['used-up', 'account', 'premium', 'quota'];

    public function __invoke(Request $request): View
    {
        $reason = (string) $request->query('reason', '');

        if (! in_array($reason, self::REASONS, true)) {
            $reason = $request->user() ? 'quota' : 'used-up';
        }

        $user = $request->user();

        /*
         * NOINDEX, and it is not optional.
         *
         * This page has no content of its own — it exists because a
         * download did not happen. Indexed, it would compete with the sound
         * pages for the very searches that bring people here, and a search
         * result reading "that was your last free download" is the worst
         * first impression the site could make.
         *
         * Shared rather than passed: partials/head is included by the
         * layout component, which has its own data scope.
         */
        view()->share('seo', [
            'noindex' => true,
            'title' => 'Downloads',
        ]);

        return view('downloads.limit', [
            'reason' => $reason,
            'user' => $user,

            // The sound they were after, so the page can offer the way back
            // to it rather than dumping them at the top of the catalogue.
            // Optional on purpose: a bad or stale slug loses the link, not
            // the page.
            'sound' => filled($request->query('sound'))
                ? Sound::where('slug', $request->query('sound'))->first()
                : null,

            'allowance' => Downloads::allowance(),
            'guestsAllowed' => Downloads::guestsAllowed(),
            'taken' => $user ? null : count(Downloads::guestTaken($request)),
            'prompt' => Downloads::prompt(),

            'plan' => $user?->currentPlan(),
            'usedToday' => $user?->downloadsToday(),

            /*
             * What the next step actually buys, read from the plans table
             * rather than written into the page. A hardcoded "unlimited
             * downloads for $9" is a promise that goes stale the first time
             * a price changes, and it goes stale silently.
             */
            'upgrade' => Plan::query()
                ->where('is_active', true)
                ->where('price_cents', '>', 0)
                ->orderBy('price_cents')
                ->first(),
        ]);
    }
}
