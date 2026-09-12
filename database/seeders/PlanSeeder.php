<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * The four rows the whole business model rests on.
 *
 * ── THE FREE ROW IS NOT OPTIONAL ─────────────────────────────────────────
 *
 * User::currentPlan() falls back to the plan with slug 'free' when somebody
 * has no subscription, and canDownload() returns false when there is no
 * plan at all. So without this row every REGISTERED user is refused every
 * download — while anonymous visitors, who go through a different path in
 * Downloads, carry on downloading normally. Signing up makes the site worse,
 * and nothing errors anywhere. Run this seeder before wondering why.
 *
 * ── THE PRICES, AND WHY THEY ARE HARD TO CHANGE LATER ────────────────────
 *
 * $14 a month, or $89 a year — $7.42 a month, 47% off.
 *
 * The monthly is deliberately unattractive. A sound-effects subscriber
 * churns fast — they download what their project needed and they are done —
 * so a monthly is worth roughly two or three payments while an annual is
 * worth one payment four times that size, taken up front, with no renewal
 * that can fail. The monthly's job is to make the annual look obvious.
 *
 * Forty-seven per cent is what actually moves people onto the annual. At the
 * 20% this file used to carry, most chose monthly and left in two months.
 * The discount is also the one number that cannot be walked back: raising it
 * later is a promotion, cutting it is a betrayal.
 *
 * PayPal keeps its own copy of every plan, and a PayPal plan with active
 * subscribers cannot be freely repriced — the normal move is to create a new
 * plan and migrate people onto it. So re-running this seeder AFTER launch
 * rewrites your database while PayPal keeps charging the old amount, and the
 * two disagree silently. Before the first subscriber this file is the source
 * of truth; after, it is a historical record.
 *
 * ── WHAT THE FEATURE LISTS MAY SAY ───────────────────────────────────────
 *
 * Only what the code actually enforces. All three of these are wired:
 *
 *   daily_download_limit  User::canDownload() — null means unlimited
 *   allows_premium        checked for guests AND users, in DownloadController
 *   ads                   Ads::paying() hides them when the setting is on
 *
 * The old lists promised "WAV master files" and a "Pro license (no credit
 * required)". Neither is a thing the plan controls today — licences live per
 * sound on the licenses table, and nothing anywhere serves a different file
 * format by plan. A pricing page that promises what the code does not do is
 * a refund request with a countdown on it.
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Free',
                'slug' => 'free',
                'description' => 'Listen to everything. Download a few sounds every day.',
                'price_cents' => 0,
                'currency' => 'USD',
                'interval' => 'month',

                /*
                 * The dial. This is the number to move when you want more
                 * people to convert — it is per registered account per day.
                 *
                 * NOT the same as the anonymous allowance, which lives in
                 * Admin → Settings → Downloads and counts total files ever,
                 * not files per day. Two different limits for two different
                 * kinds of visitor; changing one does nothing to the other.
                 */
                'daily_download_limit' => 10,
                'allows_premium' => false,
                'features' => [
                    'Unlimited previews',
                    '10 downloads a day',
                    'Standard sounds',
                    'Supported by ads',
                ],
                'sort_order' => 0,
            ],
            [
                'name' => 'Pro',
                'slug' => 'pro-monthly',
                'description' => 'Unlimited downloads, no ads. Billed monthly, cancel any time. The flexible option, priced for it.',
                'price_cents' => 1400,
                'currency' => 'USD',
                'interval' => 'month',

                // null is what "unlimited" means to canDownload(). Zero would
                // mean nobody downloads anything, which is a very different
                // product for the same money.
                'daily_download_limit' => null,
                'allows_premium' => true,
                'features' => [
                    'Unlimited downloads',
                    'Premium sounds included',
                    'No ads',
                    'Cancel any time',
                ],
                'sort_order' => 1,
            ],
            [
                'name' => 'Day Pass',
                'slug' => 'day-pass',
                'description' => '24 hours of premium sounds, no subscription. 50 downloads.',

                /*
                 * $7, NOT $5 — and the two reasons are worth keeping.
                 *
                 * PayPal charges 3.49% + $0.49 on a US online payment, plus
                 * 1.5% cross-border. That fixed forty-nine cents is what
                 * decides a small price: at $5 you lose 13% of it, nearly
                 * 15% from abroad. At $9 it is 8.9%.
                 *
                 * And the pass buyer is not comparing against Pro at all. They
                 * have a deadline and they will not take a subscription; for
                 * them the choice is nine dollars or nothing. Two dollars up
                 * from seven does not change their decision and moves the
                 * margin 31%.
                 */
                'price_cents' => 900,
                'currency' => 'USD',

                /*
                 * NOT A PAYPAL SUBSCRIPTION.
                 *
                 * `interval` here describes the ACCESS WINDOW, not a billing
                 * cycle: the pass is a one-off payment (a PayPal Order, a
                 * different API from Subscriptions) that writes a
                 * subscriptions row with ends_at = now + 24h. Subscription
                 *::active() already requires ends_at to be in the future and
                 * currentPlan() falls back to free the moment it passes, so
                 * the access model needs nothing new.
                 *
                 * Never register this one as a recurring PayPal plan. A day
                 * pass that quietly renews every morning is a chargeback.
                 */
                'interval' => 'day',

                /*
                 * CAPPED, and this is the number that stops the catalogue
                 * walking out of the door.
                 *
                 * The download rate limiter allows 200 an hour, which is
                 * 4,800 in 24 hours. Sold as "unlimited for $7", a single
                 * pass would buy an entire five-thousand-sound library —
                 * legally, inside the rules, tripping no abuse rule at all.
                 * The monthly plan has the same arithmetic but not the same
                 * risk: a subscriber who does it is visible, has an account
                 * and can be cancelled. A pass holder is gone in a day.
                 *
                 * Fifty covers any real project. Editable in Admin →
                 * Billing → Plans without touching this file.
                 */
                'daily_download_limit' => 50,
                'allows_premium' => true,
                'features' => [
                    '50 downloads',
                    'Premium sounds included',
                    'No ads for 24 hours',
                    'One payment, nothing recurring',
                ],
                'sort_order' => 2,
            ],
            [
                'name' => 'Pro Yearly',
                'slug' => 'pro-yearly',
                'description' => 'Everything in Pro, billed once a year. Works out at $7.42 a month.',

                // $89, not $168. One payment a year rather than twelve is
                // also twelve fewer chances for a renewal to fail and lock
                // somebody out of what they paid for. And $89 sits under the
                // ninety-dollar line, which $96 does not.
                'price_cents' => 8900,
                'currency' => 'USD',
                'interval' => 'year',
                'daily_download_limit' => null,
                'allows_premium' => true,
                'features' => [
                    'Everything in Pro',
                    'Save 47% — $7.42 a month',
                    'One payment a year',
                ],
                'sort_order' => 3,
            ],
        ];

        foreach ($plans as $plan) {
            Plan::updateOrCreate(['slug' => $plan['slug']], $plan);
        }
    }
}
