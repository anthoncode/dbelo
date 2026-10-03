<?php

namespace App\Http\Controllers;

use App\Models\Download;
use App\Models\Sound;
use App\Support\Downloads;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * The only way a real audio file leaves the server.
 *
 * Files live on a private disk, so this controller is the single gate where
 * the plan, the free allowance and the licence are enforced.
 *
 * THREE THINGS CHANGED HERE AND THEY ARE WORTH KNOWING APART:
 *
 *   1. A VISITOR WITHOUT AN ACCOUNT CAN DOWNLOAD, up to the allowance in
 *      Admin → Settings → Downloads. Somebody who has never heard the
 *      catalogue has no reason to fill in a form; a few files first is the
 *      trade every library of this kind makes.
 *
 *   2. A RE-DOWNLOAD IS NOT A SECOND DOWNLOAD. Fetching again a sound you
 *      already took used to cost another slot off the daily quota AND
 *      increment downloads_count — which is what orders the categories on
 *      the home page. Anybody re-fetching one file five times was pushing
 *      it up the rankings, and being charged for it.
 *
 *   3. RUNNING OUT IS A PAGE, NOT A 500-SHAPED ERROR. abort(429) served a
 *      grey Symfony page at the single highest-intent moment on the site:
 *      somebody who wants a file. That moment now gets a page that says how
 *      many they used, what an account gives them, and a button.
 *
 * What has NOT moved: stopping a script taking the whole catalogue. That is
 * a different problem, aimed at machines, and it lives in the 'downloads'
 * rate limiter, WatchTraffic and BlockIps. The free allowance is a cookie
 * and anybody can reset it in a private window — which is fine, and is the
 * point. Making the nudge do the wall's job is what leads to counting by IP,
 * and then an office or a whole mobile carrier behind CGNAT is one "person".
 */
class DownloadController extends Controller
{
    public function __invoke(Request $request, Sound $sound): Response
    {
        // 451 Unavailable For Legal Reasons: the file exists and the person
        // is allowed to ask for it — we are the ones who cannot hand it over
        // while a copyright claim is open.
        if ($sound->isUnderClaim()) {
            abort(451, 'This sound is temporarily unavailable while a copyright claim is reviewed.');
        }

        $user = $request->user();

        /*
         * ── YOUR OWN SOUND IS NOT A DOWNLOAD ─────────────────────────────
         *
         * A contributor fetching a file they uploaded is not consuming
         * anything: the master is theirs, it is already on their disk, and
         * the site is holding a copy of it. Charging a slot of their daily
         * quota for it would mean the library rationing somebody's access to
         * their own work — and the most common reason they ask for it is to
         * check that what we published sounds like what they sent, which is
         * unpaid quality control we should be encouraging.
         *
         * There is no way to game it either. The free downloads it grants
         * are free downloads OF YOUR OWN FILES, which is a circle.
         *
         * Three consequences, all deliberate:
         *
         *   · No `downloads` row. That row means "this person accepted the
         *     licence"; an author does not license their own work from us.
         *     It would also land in their download history as a sound they
         *     took from the library, which it is not.
         *   · downloads_count is untouched. Otherwise an author reloading
         *     their own file would climb the "most downloaded" ranking, and
         *     that ranking orders the home page.
         *   · Unpublished is fine. A sound still in the queue, or rejected,
         *     is exactly the one somebody needs back — "give me my file" is
         *     the whole point.
         *
         * A live copyright claim is still a wall, and for everybody. That
         * block is not about who owns the file; it is about us not handing
         * out a recording whose ownership is being disputed. It is checked
         * above this, which is why it is not repeated here.
         */
        $own = $user !== null && (int) $sound->user_id === (int) $user->id;

        if ($own) {
            $file = $sound->downloadFile('mp3');

            abort_unless($file, 404, 'No downloadable file for this sound.');

            return Storage::disk($file->disk)->download(
                $file->path,
                Downloads::filename($sound->title)
            );
        }

        abort_unless($sound->isPublished(), 404);

        /*
         * A repeat is decided BEFORE any limit is checked, because a repeat
         * is not subject to the limit. Somebody whose quota ran out today
         * can still re-fetch a file they already have — they are not taking
         * anything new, and telling them otherwise reads as the site losing
         * a file they already own.
         */
        $repeat = $user
            ? $this->userAlreadyHas($user->id, $sound->id)
            : Downloads::guestHasTaken($request, $sound->id);

        if ($block = $this->blocked($request, $sound, $user, $repeat)) {
            return $block;
        }

        $file = $sound->downloadFile('mp3');

        abort_unless($file, 404, 'No downloadable file for this sound.');

        if (! $repeat) {
            Download::create([
                // NULL is a real answer here, not a missing one: the column
                // is nullable and this row is a download by somebody with no
                // account. The licence, the IP and the agent are recorded
                // exactly as they are for a member — "downloading is
                // accepting", and the acceptance has to be on file.
                'user_id' => $user?->id,
                'sound_id' => $sound->id,
                'sound_file_id' => $file->id,
                'license_id' => $sound->license_id,
                // Freeze the terms as they stand today. If the licence
                // changes next year, this person still holds what they
                // accepted.
                'license_snapshot' => $sound->license?->toSnapshot(),
                'ip_address' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
                'created_at' => now(),
            ]);

            $sound->increment('downloads_count');

            // If a search led here, credit it. This is what turns the search
            // log from a list of words into a measure of whether search works.
            app(\App\Services\SearchLogger::class)->attributeDownload();
        }

        $response = Storage::disk($file->disk)->download(
            $file->path,
            Downloads::filename($sound->title)
        );

        /*
         * The cookie is attached to THIS response rather than queued.
         *
         * A queued cookie is added by middleware on the way out, which works
         * — but a download is a streamed response, and tying the one piece
         * of state this feature depends on to the frame the file leaves in
         * removes the question entirely. Laravel encrypts it like any other.
         */
        if (! $user && ! $repeat) {
            $response->headers->setCookie(Downloads::rememberGuest($request, $sound->id));
        }

        return $response;
    }

    /**
     * Has this member taken this sound recently enough for it to be the same
     * download rather than a new one?
     */
    protected function userAlreadyHas(int $userId, int $soundId): bool
    {
        return Download::query()
            ->where('user_id', $userId)
            ->where('sound_id', $soundId)
            ->where('created_at', '>=', now()->subDays(Downloads::REGRAB_DAYS))
            ->exists();
    }

    /**
     * The reason this person cannot have the file, as a redirect — or null.
     *
     * Every branch ends on the same page with a different reason, so there
     * is one screen to design and one place where the wording lives, rather
     * than three HTTP errors that each say a sentence and offer nothing.
     */
    protected function blocked(Request $request, Sound $sound, $user, bool $repeat): ?Response
    {
        $to = fn (string $reason) => redirect()->route('downloads.limit', [
            'reason' => $reason,
            'sound' => $sound->slug,
        ]);

        if (! $user) {
            if (! Downloads::guestsAllowed()) {
                return $to('account');
            }

            // Premium is the product. Handing it out to somebody we cannot
            // even email would make the plan meaningless.
            if ($sound->is_premium) {
                return $to('premium');
            }

            if (! $repeat && Downloads::guestRemaining($request) < 1) {
                return $to('used-up');
            }

            return null;
        }

        if ($sound->is_premium && ! $user->currentPlan()?->allows_premium) {
            return $to('premium');
        }

        // canDownload() also covers a suspended account and a missing plan.
        if (! $repeat && ! $user->canDownload($sound)) {
            return $to('quota');
        }

        return null;
    }
}
