<?php

namespace App\Console\Commands;

use App\Models\Plan;
use App\Services\PayPal\PayPalClient;
use App\Services\PayPal\PayPalException;
use App\Support\PublicUrl;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Create this site's recurring plans inside PayPal, and write their ids back.
 *
 * The admin screen has been pointing at this command by name for months:
 *
 *   "A plan exists twice: as a row here, and as a plan inside PayPal with
 *    its own id. New plans come from PlanSeeder, so the price a plan
 *    launched with is in version control, and then get their PayPal id from
 *    paypal:sync-plans once that command exists."
 *
 * This is that command. It does not invent plans — it takes the rows that
 * already exist and gives each one a twin at PayPal.
 *
 * ── IT ONLY EVER CREATES ─────────────────────────────────────────────────
 *
 * A plan that already has an id for this mode is skipped, loudly. Nothing
 * here updates or deletes anything at PayPal, and that restraint is the
 * whole safety model: a PayPal plan with live subscribers cannot be
 * repriced, and a command that tried would either fail halfway or — worse —
 * succeed on some plans and not others, leaving the two sides disagreeing
 * about what people are paying.
 *
 * Changing a price therefore stays a deliberate, manual job: new plan,
 * migrate subscribers, retire the old one. The admin screen already
 * explains that, and paypalPriceDrifted() already detects the case.
 *
 * ── SANDBOX AND LIVE ARE DIFFERENT CATALOGUES ────────────────────────────
 *
 * Ids are written to the column pair for the CURRENT mode, which Plan
 * already handles through paypalPlanId()/setPayPalIds(). Run it once in
 * sandbox and once in live; the two sets never meet, and a sandbox plan id
 * sent to the live API simply does not exist there.
 */
class SyncPayPalPlansCommand extends Command
{
    /**
     * How PayPal files what this site sells.
     *
     * Never shown to the buyer — it feeds PayPal's own risk scoring and
     * reporting — but it should still be true. This said SOFTWARE, which is a
     * valid value and the wrong one: dbelo sells downloadable audio, not
     * programs. Both are in PayPal's 446-entry product_category enum; this is
     * the entry that describes a sound library.
     */
    private const CATEGORY = 'DIGITAL_MEDIA_BOOKS_MOVIES_MUSIC';

    protected $signature = 'paypal:sync-plans
                            {--dry-run : Show what would be created and change nothing}';

    protected $description = 'Create the recurring plans inside PayPal and store their ids';

    public function handle(PayPalClient $paypal): int
    {
        if (! $paypal->isConfigured()) {
            $this->error('PayPal is not configured. Set PAYPAL_CLIENT_ID and PAYPAL_CLIENT_SECRET in .env, then run config:cache.');

            return self::FAILURE;
        }

        $mode = $paypal->mode();
        $dry = (bool) $this->option('dry-run');

        $this->line('');
        $this->line("  PayPal mode: <options=bold>{$mode}</>".($dry ? '   <fg=yellow>DRY RUN</>' : ''));

        // Said out loud rather than dropped quietly. A field that silently
        // disappears from a request is the hardest kind of difference to
        // find later, and on a dev machine this one always disappears.
        if ($this->homeUrl() === null) {
            $this->line('  <fg=gray>home_url omitted — '.PublicUrl::problem(config('app.url')).'</>');
        }

        $this->line('');

        /*
         * Recurring only.
         *
         * isRecurring() and not price_cents > 0 — the distinction matters
         * and Plan's own docblock spells it out: a one-off day pass costs
         * money and is sold through the Orders API, which never has a plan
         * id. Filtering on price alone would try to create a billing plan
         * for it, fail, and report the day pass as broken for ever.
         */
        $plans = Plan::where('is_active', true)
            ->get()
            ->filter(fn (Plan $plan) => $plan->isRecurring());

        if ($plans->isEmpty()) {
            $this->warn('  No active recurring plans to sync.');

            return self::SUCCESS;
        }

        $created = 0;
        $skipped = 0;

        foreach ($plans as $plan) {
            $existing = $plan->paypalPlanId($mode);

            if ($existing !== null) {
                $this->line("  <fg=gray>skip</>   {$plan->name} — already has {$existing}");
                $skipped++;

                continue;
            }

            $this->line("  <fg=cyan>create</> {$plan->name} · {$plan->priceForHumans()} / {$plan->interval}");

            if ($dry) {
                $created++;

                continue;
            }

            try {
                $productId = $this->product($paypal, $plan, $mode);
                $planId = $this->plan($paypal, $plan, $productId);

                $plan->setPayPalIds($productId, $planId, $mode);

                $this->line("         <fg=green>✓</> product {$productId}");
                $this->line("         <fg=green>✓</> plan    {$planId}");
                $created++;
            } catch (PayPalException $e) {
                /*
                 * One plan failing does not abort the rest. Each is an
                 * independent two-call conversation with PayPal, and a
                 * second plan has no reason to be punished because the
                 * first had a bad interval.
                 */
                $this->line("         <fg=red>✗</> {$e->getMessage()}");
            }
        }

        $this->line('');
        $this->line("  {$created} created, {$skipped} already synced.");
        $this->line('');

        if ($created > 0 && ! $dry) {
            $this->line('  Next: Admin → Plans should now show a PayPal id for each one.');
            $this->line('');
        }

        return self::SUCCESS;
    }

    /**
     * The product every plan hangs off.
     *
     * PayPal's model is product → plan → subscription, and a product is a
     * description of what is being sold rather than a price. One per plan
     * here rather than one shared: a shared product means every plan's name
     * and description at PayPal come from whichever plan created it first,
     * and that text is shown to the buyer on PayPal's own checkout screen.
     *
     * The request id makes the call idempotent. If the network drops after
     * PayPal created the product but before the response arrived, running
     * the command again returns the same product instead of making a second
     * one — which otherwise accumulates silently, because nothing on this
     * end knows the first exists. See requestId() for the catch in that.
     */
    protected function product(PayPalClient $paypal, Plan $plan, string $mode): string
    {
        // array_filter so an omitted home_url is absent rather than null:
        // PayPal validates a present-but-null field, and rejects it.
        $payload = array_filter([
            // PayPal caps name at 127 and description at 256. Trimmed here
            // rather than discovered as a 400 on the one plan whose
            // description somebody wrote three paragraphs for.
            'name' => Str::limit(config('app.name', 'dbelo').' — '.$plan->name, 127, ''),
            'description' => Str::limit((string) ($plan->description ?: $plan->name.' subscription'), 256, ''),

            /*
             * DIGITAL, and the enum is the whole story.
             *
             * product_type allows exactly PHYSICAL, DIGITAL and SERVICE.
             * This said DIGITAL_GOODS — which is a real PayPal value, just
             * from a different API: it is an item category in Orders v2. So
             * PayPal answered 400 INVALID_REQUEST with its generic "violates
             * schema" sentence, every plan failed, and no plan id was ever
             * written. DIGITAL also means no shipping address is collected,
             * which was the original intent.
             *
             * The response had named the field in details[] all along; it was
             * PayPalException that threw it away. It no longer does.
             */
            'type' => 'DIGITAL',

            'category' => self::CATEGORY,

            // null on a dev machine, and then not sent at all. See homeUrl().
            'home_url' => $this->homeUrl(),
        ], fn ($value) => $value !== null);

        $response = $paypal->post(
            '/v1/catalogs/products',
            $payload,
            requestId: $this->requestId("product-{$mode}-{$plan->slug}", $payload),
        );

        return (string) $response['id'];
    }

    /**
     * The site's address, but only if PayPal could actually open it.
     *
     * ── WHY THIS IS NOT JUST config('app.url') ──────────────────────────
     *
     * home_url is optional, and PayPal shows it to a buyer who wants to know
     * who is charging them. So PayPal checks it, and more strictly than its
     * own published schema admits — the schema says `format: uri, maxLength
     * 2000` and nothing else, while the API answers:
     *
     *   400 INVALID_REQUEST → home_url=http://dbelo.test [INVALID_PARAMETER_SYNTAX]
     *
     * Which is correct of it. `dbelo.test` is a Herd address that exists on
     * one laptop, and `.test` is reserved precisely so it can never resolve
     * on the public internet. A link PayPal cannot follow is worse than no
     * link, and it cost every sync run until this existed.
     *
     * Returning null rather than guessing a replacement: inventing
     * `https://dbelo.com` while developing would put the live address on a
     * sandbox product, which is the kind of small lie that is believed for
     * months. In production APP_URL is already the real https address and
     * this returns it unchanged.
     *
     * The rule itself lives in PublicUrl, because the checkout needs the
     * same answer about its return_url — see that class for what happened
     * when it did not.
     */
    protected function homeUrl(): ?string
    {
        return PublicUrl::orNull(config('app.url'));
    }

    /**
     * An idempotency key that changes when the request changes.
     *
     * PayPal-Request-Id exists so a create lost to a dropped connection can
     * be repeated without making two of the thing. A key built from the plan
     * alone does that — and also pins the FIRST payload ever sent under it.
     *
     * Which is a trap, and this command walked into it: send a corrected
     * payload under a key PayPal has already seen and it may replay the
     * original answer, rejection included. A genuine fix then looks like no
     * fix at all, and the next hour goes into debugging code that is already
     * right.
     *
     * Hashing the payload into the key keeps both properties: an identical
     * repeat is still deduplicated, a corrected one is a new request.
     */
    protected function requestId(string $prefix, array $payload): string
    {
        $hash = substr(sha1(json_encode($payload, JSON_THROW_ON_ERROR)), 0, 10);

        return substr("{$prefix}-{$hash}", 0, 108); // PayPal caps the header at 108 characters.
    }

    /**
     * The billing plan: the price and how often.
     *
     * total_cycles 0 means "until cancelled", which is what a subscription
     * is. A finite number here would silently end everybody's access after
     * N payments, and nothing in this application would know why.
     *
     * setup_fee 0 and auto_bill_outstanding true are the plain-subscription
     * defaults; stated rather than omitted because PayPal's own defaults
     * for an absent payment_preferences block have changed before.
     */
    protected function plan(PayPalClient $paypal, Plan $plan, string $productId): string
    {
        $amount = number_format($plan->price_cents / 100, 2, '.', '');
        $currency = strtoupper((string) ($plan->currency ?: $paypal->currency()));

        $payload = [
            'product_id' => $productId,
            'name' => $plan->name,
            'description' => Str::limit((string) ($plan->description ?: $plan->name), 127, ''),
            'status' => 'ACTIVE',
            'billing_cycles' => [[
                'frequency' => [
                    'interval_unit' => $plan->paypalIntervalUnit(),
                    'interval_count' => 1,
                ],
                'tenure_type' => 'REGULAR',
                'sequence' => 1,
                'total_cycles' => 0,
                'pricing_scheme' => [
                    'fixed_price' => [
                        'value' => $amount,
                        'currency_code' => $currency,
                    ],
                ],
            ]],
            'payment_preferences' => [
                'auto_bill_outstanding' => true,
                'setup_fee' => ['value' => '0.00', 'currency_code' => $currency],
                'setup_fee_failure_action' => 'CONTINUE',
                /*
                 * Three attempts before PayPal gives up and suspends.
                 *
                 * The suspension is what this application acts on —
                 * SubscriptionEvents treats PAYMENT.FAILED as a note and
                 * SUSPENDED as a stop — so this number is, in practice, how
                 * much patience somebody's expired card is given before
                 * they lose access.
                 */
                'payment_failure_threshold' => 3,
            ],
            'taxes' => [
                'percentage' => '0',
                // The listed price is what the buyer pays. A site operating
                // from one country with no tax registration elsewhere has
                // nothing to add, and claiming otherwise on PayPal's
                // checkout screen would be a figure we cannot account for.
                'inclusive' => true,
            ],
        ];

        $response = $paypal->post(
            '/v1/billing/plans',
            $payload,
            requestId: $this->requestId("plan-{$productId}", $payload),
        );

        return (string) $response['id'];
    }
}
