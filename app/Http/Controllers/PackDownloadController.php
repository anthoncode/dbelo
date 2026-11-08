<?php

namespace App\Http\Controllers;

use App\Models\Collection as Pack;
use App\Services\PackArchive;
use App\Support\Bots;
use App\Support\Downloads;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A pack, handed over as one zip.
 *
 * The gate is the same one DownloadController runs, asked once for a set
 * instead of once for a file — and the differences are only the ones the
 * set creates:
 *
 *   ENTITLEMENT IS PER SOUND, NOT PER PACK. A pack has no premium flag; it
 *   has premium_count. So the archive carries what this person may take and
 *   says what it left behind, rather than refusing the eight free sounds
 *   because four of their neighbours are paid.
 *
 *   THE QUOTA IS ASKED FOR A NUMBER. canDownload() answers "may they take
 *   one more today". A pack of twelve needs "may they take twelve", and the
 *   difference is the whole reason a pack button could otherwise empty a
 *   free allowance in a way nobody intended.
 *
 *   IT CAN BE TOO BIG. A browser download has no resume, and on shared
 *   hosting a long stream dies. Past PackArchive::MAX_SOUNDS it says so.
 *
 * Everything else — the signature, the bot filter, the licence snapshot per
 * sound, the repeat rule — is the single download's behaviour applied to a
 * list, and lives in PackArchive so that the two cannot drift.
 */
class PackDownloadController extends Controller
{
    public function __invoke(Request $request, string $pack, PackArchive $archive): Response
    {
        /*
         * Looked up through featured(), exactly as the pack page does.
         * Route model binding would resolve ANY collection, and most
         * collections are somebody's private folder — so a bare binding
         * here would be a download URL for other people's saved lists.
         * The scope is the check, so it cannot be forgotten.
         */
        $pack = Pack::featured()->where('slug', $pack)->firstOrFail();

        // Same reasoning as the single download: the URL has to have been
        // issued on a page, not constructed from a slug. A pack URL is
        // worth more to a scraper than a sound's — one request, the whole
        // set — so this matters here most.
        if (! Downloads::signatureIsValid($request)) {
            return redirect()->route('packs.show', $pack);
        }

        $user = $request->user();
        $sounds = $archive->available($pack, $user);

        $to = fn (string $reason) => redirect()->route('downloads.limit', [
            'reason' => $reason,
            'pack' => $pack->slug,
        ]);

        /*
         * Nothing to give.
         *
         * Either the pack is empty or every sound in it needs a plan this
         * person does not have. The second is the real case, and it is an
         * OFFER rather than an error — which is why it goes to the limit
         * screen with 'premium' instead of returning an empty archive. A
         * zip containing one text file saying "you get nothing" is the
         * worst possible version of this.
         */
        if ($sounds->isEmpty()) {
            return $pack->sounds_count > 0
                ? $to('premium')
                : redirect()->route('packs.show', $pack);
        }

        /*
         * Too big goes back to the PACK, not to the limit screen.
         *
         * DownloadLimitController knows four reasons and rewrites anything
         * else to 'quota' — so inventing a fifth would land somebody on a
         * page explaining a daily allowance they have not reached. A wrong
         * explanation is worse than none.
         *
         * The pack page already carries the honest sentence for this case,
         * next to where the button would have been, so sending them there
         * puts the reason under their eyes. And the UI does not offer the
         * button at this size at all — reaching here means a hand-built
         * URL.
         */
        if ($sounds->count() > PackArchive::MAX_SOUNDS) {
            return redirect()->route('packs.show', $pack);
        }

        if ($guard = $this->blocked($request, $user, $sounds->count())) {
            return $guard;
        }

        /*
         * A bot that got this far gets nothing, rather than the file
         * without a row.
         *
         * The single download serves a crawler and declines to count it,
         * because refusing there costs a person a file over a header they
         * do not control. A pack is the opposite trade: it is the whole
         * curated set in one request, it is expensive to stream, and no
         * crawler has a use for forty MP3s. Here the cheap mistake is
         * saying no.
         */
        if (Bots::looksAutomated($request->userAgent())) {
            return redirect()->route('packs.show', $pack);
        }

        $response = response()->streamDownload(
            fn () => $archive->stream($pack, $user, $request, $sounds),
            PackArchive::filename($pack),
            [
                'Content-Type' => 'application/zip',
                // No length can be sent: the size is not known until the
                // stream has finished. Without this, a proxy that buffers
                // would hold the whole archive in memory to work one out,
                // which is the thing streaming exists to avoid.
                'X-Accel-Buffering' => 'no',
            ],
        );

        /*
         * The guest cookie is updated once, with every sound taken.
         *
         * rememberGuest returns a cookie rather than setting one, so it has
         * to be attached to the response — and it has to be the response
         * that actually goes out, not a new one.
         */
        if (! $user) {
            foreach ($sounds as $sound) {
                $response->headers->setCookie(Downloads::rememberGuest($request, $sound->id));
            }
        }

        return $response;
    }

    /**
     * May this person take this many sounds right now?
     *
     * The single download's gate asks about one file. This asks about a
     * count, and that is the only real difference — plus the one sentence
     * it has to add: refusing a pack has to say how many they could take,
     * or "not enough" reads as "broken".
     */
    protected function blocked(Request $request, $user, int $wanted): ?Response
    {
        $to = fn (string $reason) => redirect()->route('downloads.limit', ['reason' => $reason]);

        if (! $user) {
            if (! Downloads::guestsAllowed()) {
                return $to('account');
            }

            return Downloads::guestRemaining($request) >= $wanted ? null : $to('used-up');
        }

        if ($user->isSuspended()) {
            return $to('quota');
        }

        $plan = $user->currentPlan();

        if (! $plan) {
            return $to('quota');
        }

        if ($plan->isUnlimited()) {
            return null;
        }

        $left = max(0, (int) $plan->daily_download_limit - $user->downloadsToday());

        return $left >= $wanted ? null : $to('quota');
    }
}
