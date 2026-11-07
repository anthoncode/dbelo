<?php

namespace App\Console\Commands;

use App\Models\WebhookEvent;
use App\Services\PayPal\EventRouter;
use App\Services\PayPal\PayPalClient;
use App\Services\PayPal\PayPalException;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Go and fetch what PayPal was supposed to send.
 *
 * ── WHY THIS EXISTS ──────────────────────────────────────────────────────
 *
 * The webhook endpoint is the designed path and it works. That was proved
 * the hard way, by elimination: an internal dispatch answered 200 and
 * recorded the event; an external POST from another machine answered 200
 * and recorded the event; the site is live, no address is blocked, and the
 * host is not filtering the route. Everything on this side is sound.
 *
 * And PayPal's events still do not arrive. The API lists them — a
 * BILLING.SUBSCRIPTION.ACTIVATED sitting there, generated the moment a real
 * subscription started — while the endpoint that should have received it
 * never saw a request. Even an explicit resend produced nothing.
 *
 * Waiting is not a plan. PayPal retries for three days and then stops, and
 * the event stays readable in their API for ever after, unprocessed. Which
 * means the revenue it represents is never recorded: no subscription charge
 * and, worse, no RENEWAL, month after month, silently.
 *
 * So this pulls instead of waiting. Same events, same handlers, same
 * duplicate guard — a second door into the identical room.
 *
 * ── THIS IS NOT A WORKAROUND FOR A BUG WE GAVE UP ON ─────────────────────
 *
 * It is worth keeping even after deliveries start working. A webhook is a
 * push from a system we do not control, over a network neither of us
 * controls; this is the audit that notices when a push was missed. Both,
 * together, are what "we did not lose a payment" actually means.
 *
 * ── ON SIGNATURES ────────────────────────────────────────────────────────
 *
 * Events arriving at the webhook URL are verified by signature, and must
 * be: that URL is public, so without the check it is "anybody who can POST
 * JSON grants themselves Pro".
 *
 * These events are not signature-checked, and that is NOT a shortcut. They
 * did not arrive over a public URL at all — this process asked PayPal for
 * them over an authenticated TLS connection using our own credentials.
 * Nobody can put a forged event into that response without already holding
 * the credentials, at which point a signature would protect nothing either.
 * The provenance is stronger here, not weaker.
 *
 * Recorded with mode and source so that a row's origin is never a guess.
 */
class FetchPayPalEventsCommand extends Command
{
    protected $signature = 'paypal:fetch-events
                            {--hours=72 : How far back to look}
                            {--limit=50 : Most events to pull in one run}
                            {--dry-run : List what would be processed and change nothing}';

    protected $description = 'Pull webhook events from PayPal and process anything not seen yet';

    public function handle(PayPalClient $paypal, EventRouter $router): int
    {
        if (! $paypal->isConfigured()) {
            $this->error('PayPal is not configured.');

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $hours = max(1, (int) $this->option('hours'));
        $limit = max(1, min(100, (int) $this->option('limit')));

        try {
            $response = $paypal->get('/v1/notifications/webhooks-events', [
                'page_size' => $limit,
                /*
                 * A window, not everything. PayPal keeps events for a long
                 * time and re-reading all of history every few minutes would
                 * be slow for no gain — the duplicate guard would reject
                 * almost all of it. 72 hours comfortably covers PayPal's own
                 * three-day retry period, so nothing can slip between the
                 * two mechanisms.
                 */
                'start_time' => now()->subHours($hours)->toIso8601ZuluString(),
                'end_time' => now()->toIso8601ZuluString(),
            ]);
        } catch (PayPalException $e) {
            // Reported, not thrown: this runs on a schedule, and a minute
            // when PayPal is unreachable must not look like a broken
            // command. The next run picks up the same window.
            report($e);
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $events = (array) ($response['events'] ?? []);

        $this->line('');
        $this->line('  PayPal mode: <options=bold>'.$paypal->mode().'</>'.($dry ? '   <fg=yellow>DRY RUN</>' : ''));
        $this->line('  '.count($events).' event(s) in the last '.$hours.'h');
        $this->line('');

        $new = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($events as $payload) {
            $id = (string) ($payload['id'] ?? '');
            $type = (string) ($payload['event_type'] ?? '');

            if ($id === '' || $type === '') {
                continue;
            }

            $when = $this->time($payload['create_time'] ?? null);
            $label = str_pad($type, 34).' '.($when?->format('d M H:i') ?? '');

            if ($dry) {
                $seen = WebhookEvent::where('event_id', $id)->exists();
                $this->line($seen ? "  <fg=gray>seen</>   {$label}" : "  <fg=cyan>new</>    {$label}");
                $seen ? $skipped++ : $new++;

                continue;
            }

            /*
             * The list response does not always carry the resource, and
             * every handler reads payload['resource']. Fetched individually
             * when it is missing — one extra call, only for the events that
             * need it, and only once each because the next run will find
             * them already recorded.
             */
            if (blank($payload['resource'] ?? null)) {
                try {
                    $payload = $paypal->get("/v1/notifications/webhooks-events/{$id}") ?: $payload;
                } catch (PayPalException $e) {
                    report($e);
                    $this->line("  <fg=red>✗</>     {$label}  could not fetch the resource");
                    $failed++;

                    continue;
                }
            }

            $event = WebhookEvent::record($id, [
                'gateway' => 'paypal',
                'event_type' => $type,
                'mode' => $paypal->mode(),
                // See the class docblock. True because of where this came
                // from, not because a signature was checked.
                'verified' => true,
                'status' => WebhookEvent::PENDING,
                'resource_id' => $payload['resource']['id'] ?? null,
                'payload' => $payload,
            ]);

            // Null means the webhook already delivered it, or an earlier run
            // took it. Doing nothing is the point.
            if (! $event) {
                $skipped++;

                continue;
            }

            try {
                $router->handle($event);
                $this->line("  <fg=green>✓</>     {$label}  {$event->fresh()->status}");
                $new++;
            } catch (Throwable $e) {
                /*
                 * One bad event does not stop the rest. The row stays FAILED
                 * and shows on the admin screen, where somebody can see that
                 * a payment needs attention — which is the whole reason the
                 * row is written before it is acted on.
                 */
                $event->markFailed($e->getMessage());
                report($e);
                $this->line("  <fg=red>✗</>     {$label}  {$e->getMessage()}");
                $failed++;
            }
        }

        $this->line('');
        $this->line("  {$new} processed, {$skipped} already known, {$failed} failed.");
        $this->line('');

        // SUCCESS even with failures: the command did its job, and a
        // non-zero exit on a scheduled task is an alert about the task, not
        // about the data. The FAILED rows are the alert about the data.
        return self::SUCCESS;
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
}
