<?php

namespace App\Services;

use App\Models\AbuseSignal;
use App\Models\LoginAttempt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reads the access log and the download log for shapes a person would spot.
 *
 * The distinction that matters: the middleware and the listener RECORD; this
 * INTERPRETS. Recording has to be cheap because it runs on every request;
 * interpreting can be expensive because it runs when somebody opens a screen
 * or when the scheduler asks. Mixing the two is how logging becomes the
 * outage.
 *
 * Everything here looks for patterns that are invisible one row at a time.
 * A single failed login is nothing. Forty failed logins against forty
 * different addresses from one IP is a credential-stuffing run, and no
 * per-request rate limit will ever notice it, because each individual
 * attempt is perfectly ordinary.
 */
class SecurityWatch
{
    /** One address trying this many different accounts is not a person. */
    public const STUFFING_ACCOUNTS = 6;

    /** One account attacked from this many addresses. */
    public const SPRAY_IPS = 4;

    /** One account downloading from this many addresses at once. */
    public const SHARED_IPS = 4;

    /**
     * Everything worth raising, in one pass. Called by the scheduler.
     */
    public function scan(): int
    {
        $raised = 0;
        $raised += $this->credentialStuffing();
        $raised += $this->passwordSpray();
        $raised += $this->sharedAccounts();

        return $raised;
    }

    /**
     * One address, many different accounts.
     *
     * The classic list-of-leaked-passwords run. Invisible to a rate limit
     * keyed on email+IP — which is what Fortify uses, correctly — because
     * every single attempt is against a different email and so never
     * approaches the per-email limit.
     */
    private function credentialStuffing(): int
    {
        if (! Schema::hasTable('login_attempts')) {
            return 0;
        }

        $rows = DB::table('login_attempts')
            ->selectRaw('ip_address, COUNT(DISTINCT email) as accounts, COUNT(*) as attempts')
            ->whereNotNull('ip_address')
            ->where('outcome', '!=', 'success')
            ->where('created_at', '>=', now()->subDay())
            ->groupBy('ip_address')
            ->havingRaw('COUNT(DISTINCT email) >= ?', [self::STUFFING_ACCOUNTS])
            ->get();

        foreach ($rows as $row) {
            AbuseSignal::raise(
                'credential_stuffing',
                $row->ip_address,
                null,
                "{$row->accounts} different accounts tried, {$row->attempts} attempts in 24 hours",
                (int) $row->attempts,
            );
        }

        return $rows->count();
    }

    /**
     * One account, many addresses.
     *
     * The mirror image, and the one that means somebody specific is being
     * targeted rather than everybody being sprayed. Worth telling that
     * person, once mail works.
     */
    private function passwordSpray(): int
    {
        if (! Schema::hasTable('login_attempts')) {
            return 0;
        }

        $rows = DB::table('login_attempts')
            ->selectRaw('email, COUNT(DISTINCT ip_address) as sources, COUNT(*) as attempts')
            ->whereNotNull('email')
            ->where('outcome', '!=', 'success')
            ->where('created_at', '>=', now()->subDay())
            ->groupBy('email')
            ->havingRaw('COUNT(DISTINCT ip_address) >= ?', [self::SPRAY_IPS])
            ->get();

        foreach ($rows as $row) {
            $userId = DB::table('users')->where('email', $row->email)->value('id');

            AbuseSignal::raise(
                'spray',
                null,
                $userId ? (int) $userId : null,
                "{$row->email} attacked from {$row->sources} addresses, {$row->attempts} attempts in 24 hours",
                (int) $row->attempts,
            );
        }

        return $rows->count();
    }

    /**
     * One account downloading from several places at once.
     *
     * This is the money leak, and it is not a bot: it is a password being
     * passed around. Per-IP limits cannot see it because each address stays
     * comfortably under the threshold — that is the whole point of sharing.
     *
     * Deliberately NOT enforced automatically. A photographer on a train
     * switching between wifi and mobile data trips this honestly, and
     * cutting off a paying customer on a guess is worse than the theft.
     * A person looks at the pattern and decides.
     */
    private function sharedAccounts(): int
    {
        if (! Schema::hasTable('downloads')) {
            return 0;
        }

        $rows = DB::table('downloads')
            ->selectRaw('user_id, COUNT(DISTINCT ip_address) as sources, COUNT(*) as downloads')
            ->whereNotNull('user_id')
            ->whereNotNull('ip_address')
            ->where('created_at', '>=', now()->subDay())
            ->groupBy('user_id')
            ->havingRaw('COUNT(DISTINCT ip_address) >= ?', [self::SHARED_IPS])
            ->get();

        foreach ($rows as $row) {
            AbuseSignal::raise(
                'shared_account',
                null,
                (int) $row->user_id,
                "{$row->downloads} downloads from {$row->sources} different addresses in 24 hours",
                (int) $row->downloads,
            );
        }

        return $rows->count();
    }

    /* ═══════════════════════════ For the screens ═══════════════════════════ */

    /**
     * What is being watched, and what it would take to trigger.
     *
     * Read from the same constants the detectors use, so the screen cannot
     * drift from the behaviour. That matters more than it sounds: an abuse
     * screen spends almost all of its life EMPTY, and an empty screen that
     * does not say what it is looking for is indistinguishable from one that
     * is not looking at all. This is what makes "nothing here" mean
     * something.
     *
     * `calibrated` is the honest part. Three of these count distinct things
     * — accounts, addresses — and a threshold on a count of distinct things
     * holds regardless of how busy the site is. The other two are rates, and
     * a rate threshold is a guess until there is real traffic to measure. It
     * is written down rather than hidden, because the person who has to
     * raise the number later needs to know which ones were ever grounded in
     * anything.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function rules(): array
    {
        $window = \App\Http\Middleware\WatchTraffic::WINDOW;

        return [
            [
                'kind' => 'credential_stuffing',
                'trigger' => 'One address fails against '.self::STUFFING_ACCOUNTS.' or more different accounts',
                'window' => 'in 24 hours',
                'calibrated' => true,
                'why' => "Fortify's limit is keyed on email plus address, so an attacker working through a leaked password list never comes near it — every attempt is against a different email.",
            ],
            [
                'kind' => 'spray',
                'trigger' => 'One account fails from '.self::SPRAY_IPS.' or more addresses',
                'window' => 'in 24 hours',
                'calibrated' => true,
                'why' => 'Somebody specific is being targeted rather than everybody being sprayed. Worth telling that person.',
            ],
            [
                'kind' => 'shared_account',
                'trigger' => 'One account downloads from '.self::SHARED_IPS.' or more addresses',
                'window' => 'in 24 hours',
                'calibrated' => true,
                'why' => 'Not a bot — a password being passed around. Per-address limits cannot see it, because staying under them is the point of sharing.',
            ],
            [
                'kind' => 'rate',
                'trigger' => 'One address makes '.\App\Http\Middleware\WatchTraffic::RATE_THRESHOLD.' requests',
                'window' => "in {$window} minutes",
                'calibrated' => false,
                'why' => 'A guess until there is traffic to measure against. Raise it if your own browsing trips it; lower it once you know what a busy hour looks like.',
            ],
            [
                'kind' => 'enumeration',
                'trigger' => 'One address opens '.\App\Http\Middleware\WatchTraffic::ENUMERATION_THRESHOLD.' sound pages',
                'window' => "in {$window} minutes",
                'calibrated' => false,
                'why' => 'The shape of somebody copying the catalogue. Also a guess — and a search engine indexing you properly will look similar.',
            ],
        ];
    }

    /** @return array<string, int> */
    public function summary(): array
    {
        if (! Schema::hasTable('login_attempts')) {
            return ['failed' => 0, 'lockouts' => 0, 'admin' => 0, 'signals' => 0];
        }

        $since = now()->subDay();

        return [
            'failed' => LoginAttempt::failed()->where('created_at', '>=', $since)->count(),
            'lockouts' => LoginAttempt::where('outcome', 'lockout')->where('created_at', '>=', $since)->count(),
            'admin' => LoginAttempt::where('is_admin', true)->where('outcome', 'success')->where('created_at', '>=', $since)->count(),
            'signals' => Schema::hasTable('abuse_signals') ? AbuseSignal::open()->count() : 0,
        ];
    }

    /**
     * Addresses with the most failures, for the access-log sidebar.
     *
     * @return array<int, array{ip: string, failures: int, accounts: int}>
     */
    public function topOffenders(int $limit = 6): array
    {
        if (! Schema::hasTable('login_attempts')) {
            return [];
        }

        return DB::table('login_attempts')
            ->selectRaw('ip_address, COUNT(*) as failures, COUNT(DISTINCT email) as accounts')
            ->whereNotNull('ip_address')
            ->where('outcome', '!=', 'success')
            ->where('created_at', '>=', now()->subWeek())
            ->groupBy('ip_address')
            ->orderByDesc('failures')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'ip' => $row->ip_address,
                'failures' => (int) $row->failures,
                'accounts' => (int) $row->accounts,
            ])
            ->all();
    }
}
