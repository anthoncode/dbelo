<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Models\Transaction;
use App\Services\PayPal\PayPalClient;
use App\Services\PayPal\PayPalException;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Reconcile each subscription's charges against PayPal's own record.
 *
 * ── WHY THIS EXISTS ──────────────────────────────────────────────────────
 *
 * A subscription charge is recorded here when PAYMENT.SALE.COMPLETED
 * arrives. The first real subscription produced no such row, and the reason
 * is worth stating because it is not a bug anybody could have debugged:
 * that event type was not ticked on the webhook at the time, so PayPal
 * NEVER GENERATED IT. There was nothing lost in transit and nothing for
 * paypal:fetch-events to go and collect — the event does not exist.
 *
 * The money moved anyway. PayPal knows exactly what it charged; it simply
 * never told us, because we never asked to be told.
 *
 * So this asks. /v1/billing/subscriptions/{id}/transactions is PayPal's own
 * ledger for a subscription, and it is true whatever did or did not happen
 * to the webhooks.
 *
 * ── WHAT IT IS FOR AFTERWARDS ────────────────────────────────────────────
 *
 * Not only for filling that one hole. Run nightly over a short window, it
 * is the audit that answers "did we record every charge?" without trusting
 * the two mechanisms that were supposed to record them. A payment system
 * that can only be checked by the same path that writes it cannot be
 * checked at all.
 *
 * ── IT ONLY FILLS GAPS ───────────────────────────────────────────────────
 *
 * A charge already recorded is left exactly as it is. The webhook and the
 * checkout return write from the event that actually happened; this writes
 * from a summary. Where they disagree the event wins, and the safest way to
 * guarantee that is never to overwrite.
 *
 * Which also means: this cannot repair a row that is wrong, only add one
 * that is missing. Repairing is a deliberate act and belongs in a human's
 * hands.
 */
class FetchPayPalChargesCommand extends Command
{
    /** Stamped into the payload so a row's origin is never a guess. */
    public const SOURCE = 'api_charges';

    protected $signature = 'paypal:fetch-charges
                            {--days=7 : How far back to reconcile}
                            {--subscription= : One subscription id, instead of all}
                            {--dry-run : List what is missing and write nothing}';

    protected $description = "Compare each subscription's charges against PayPal and record any that are missing";

    /**
     * PayPal's words for what happened, in ours.
     *
     * Listed rather than lowercased, because the two vocabularies only look
     * alike: PayPal's DECLINED and FAILED are one thing to this
     * application — money that did not arrive — and a mapping by accident
     * of spelling would invent a status that Transaction::scopeEarned()
     * has never heard of and silently filter nothing.
     */
    protected const STATUS = [
        'COMPLETED' => 'completed',
        'REFUNDED' => 'refunded',
        'PARTIALLY_REFUNDED' => 'partially_refunded',
        'PENDING' => 'pending',
        'DECLINED' => 'failed',
        'FAILED' => 'failed',
    ];

    public function handle(PayPalClient $paypal): int
    {
        if (! $paypal->isConfigured()) {
            $this->error('PayPal is not configured.');

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $days = max(1, (int) $this->option('days'));

        $since = now()->subDays($days);
        $until = now();

        /*
         * Recurring subscriptions only.
         *
         * A pass lives in this same table — see the module notes — with a
         * CAPTURE id in external_id, and this endpoint only knows
         * subscription ids. Asking it about a capture is a 404 per pass,
         * every run, for ever. isRecurring() is the same question the rest
         * of the module asks, so the filter cannot drift from it.
         */
        $subscriptions = Subscription::query()
            ->where('gateway', 'paypal')
            ->whereNotNull('external_id')
            ->when($this->option('subscription'), fn ($q, $id) => $q->where('external_id', $id))
            ->with(['plan', 'user'])
            ->get()
            ->filter(fn (Subscription $s) => (bool) $s->plan?->isRecurring());

        $this->line('');
        $this->line('  PayPal mode: <options=bold>'.$paypal->mode().'</>'.($dry ? '   <fg=yellow>DRY RUN</>' : ''));
        $this->line('  '.$subscriptions->count().' subscription(s), since '.$since->format('d M Y'));
        $this->line('');

        $added = 0;
        $known = 0;
        $failed = 0;

        foreach ($subscriptions as $subscription) {
            try {
                $response = $paypal->get("/v1/billing/subscriptions/{$subscription->external_id}/transactions", [
                    // Both are required by PayPal; omitting either is a 400.
                    'start_time' => $since->toIso8601ZuluString(),
                    'end_time' => $until->toIso8601ZuluString(),
                ]);
            } catch (PayPalException $e) {
                report($e);
                $this->line("  <fg=red>✗</> {$subscription->external_id}  {$e->getMessage()}");
                $failed++;

                continue;
            }

            foreach ((array) ($response['transactions'] ?? []) as $charge) {
                $id = (string) ($charge['id'] ?? '');

                if ($id === '') {
                    continue;
                }

                $gross = $this->cents($charge['amount_with_breakdown']['gross_amount']['value'] ?? null);
                $label = str_pad($id, 20).' $'.number_format($gross / 100, 2).'  '
                    .str_pad((string) ($charge['status'] ?? '?'), 18)
                    .($subscription->plan?->name ?? '');

                /*
                 * Already recorded by the webhook or an earlier run. The
                 * check is on the SAME unique key the other writers use —
                 * (gateway, external_id) — because a second row for one
                 * charge is a month of revenue counted twice, and no later
                 * report could tell which was the duplicate.
                 */
                $exists = Transaction::where('gateway', 'paypal')->where('external_id', $id)->exists();

                if ($exists) {
                    $known++;

                    if ($dry) {
                        $this->line("  <fg=gray>known</> {$label}");
                    }

                    continue;
                }

                $this->line("  <fg=cyan>missing</> {$label}");
                $added++;

                if ($dry) {
                    continue;
                }

                try {
                    $this->record($subscription, $charge, $id, $gross);
                } catch (Throwable $e) {
                    report($e);
                    $this->line("          <fg=red>✗</> {$e->getMessage()}");
                    $added--;
                    $failed++;
                }
            }
        }

        $this->line('');
        $this->line($dry
            ? "  {$added} missing, {$known} already recorded, {$failed} failed."
            : "  {$added} recorded, {$known} already known, {$failed} failed.");
        $this->line('');

        if ($dry && $added > 0) {
            $this->line('  Run it again without --dry-run to write them.');
            $this->line('');
        }

        return self::SUCCESS;
    }

    protected function record(Subscription $subscription, array $charge, string $id, int $gross): void
    {
        $breakdown = (array) ($charge['amount_with_breakdown'] ?? []);

        $fee = $this->cents($breakdown['fee_amount']['value'] ?? null);

        /*
         * PayPal's own net when it gives one, ours only as a fallback.
         *
         * gross - fee is right until the day it is not — currency
         * conversion, a platform fee, a partial refund already applied —
         * and the figure that has to survive is the one the gateway agrees
         * with, because this row exists to reconcile against it.
         */
        $net = $this->cents($breakdown['net_amount']['value'] ?? null) ?: max(0, $gross - $fee);

        Transaction::create([
            'gateway' => 'paypal',
            'external_id' => $id,

            // From OUR records, never from the charge's payer_email: a
            // person can pay with a PayPal account registered to a
            // different address than the one they signed up with here.
            'user_id' => $subscription->user_id,
            'subscription_id' => $subscription->id,
            'plan_id' => $subscription->plan_id,
            'plan_name' => $subscription->plan?->name,
            'email' => $subscription->user?->email,

            'type' => 'subscription',
            'status' => self::STATUS[(string) ($charge['status'] ?? '')] ?? 'pending',

            'amount_cents' => $gross,
            'fee_cents' => $fee,
            'net_cents' => $net,
            'currency' => strtoupper((string) ($breakdown['gross_amount']['currency_code'] ?? 'USD')),

            // When the money moved, not when this row was written. A
            // monthly total built on created_at would put a backfilled
            // charge in the month somebody happened to run this command.
            'paid_at' => $this->time($charge['time'] ?? null) ?? now(),

            'payload' => $charge + ['source' => self::SOURCE],
        ]);
    }

    /** PayPal sends ISO 8601; a bad one must not take the run down. */
    protected function time(?string $value): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /** "14.00" into 1400, through round() — see the module notes. */
    protected function cents(?string $value): int
    {
        return blank($value) ? 0 : (int) round(((float) $value) * 100);
    }
}
