<?php

use App\Models\Plan;
use App\Models\WebhookEvent;
use App\Services\PayPal\PayPalClient;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * PayPal — the status screen, deliberately without a single editable field.
 *
 * ── WHY NOTHING HERE CAN BE TYPED ────────────────────────────────────────
 *
 * Google OAuth and the captcha DO have editable secrets, stored encrypted in
 * the settings table. PayPal breaks that convention on purpose, and the
 * reason is not tidiness:
 *
 *  - Admin → Backups produces a downloadable .sql. Everything in `settings`
 *    rides along in it. A Google client secret in that file is bad; a LIVE
 *    PayPal secret is somebody creating subscriptions, issuing refunds and
 *    reading transactions on the merchant account.
 *
 *  - An editable field has to write somewhere. Writing .env from a web form
 *    would mean the web process can rewrite its own configuration — which
 *    turns any future admin-account compromise from "they saw the panel"
 *    into "they changed what the application connects to, permanently".
 *
 *  - Settings are encrypted with APP_KEY. Rotating it makes them
 *    unrecoverable, and the moment that hurts most is mid-month with live
 *    subscriptions renewing.
 *
 *  - Credentials in the database travel with the database. Restore a
 *    production dump onto a laptop and the laptop is pointed at LIVE. With
 *    .env, the laptop's own file says sandbox no matter what dump is loaded.
 *
 * What was actually missing was not a form: it was any way to know from the
 * panel whether PayPal is connected, which environment, and whether the
 * plans here match the plans there. That is what this screen is.
 */
new #[Layout('layouts.admin')] #[Title('PayPal')] class extends Component
{
    /**
     * The result of the last "Test connection".
     *
     * Safe to hold in a public property — and worth stating, because the
     * rule elsewhere in this project is that secrets never touch one.
     * PayPalClient::health() returns a mode, a base URL and a message built
     * from PayPal's status, issue name and debug id. The credentials cannot
     * reach it: nothing in PayPalClient or PayPalException ever puts them
     * into a message.
     */
    public ?array $probe = null;

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    private function client(): PayPalClient
    {
        return new PayPalClient;
    }

    /* ═══════════════════════════ Reading the environment ═══════════════════════════ */

    #[Computed]
    public function conf(): array
    {
        $client = $this->client();

        return [
            'mode' => $client->mode(),
            'base' => $client->baseUrl(),
            'configured' => $client->isConfigured(),
            'has_secret' => trim((string) config('services.paypal.client_secret')) !== '',
            'webhook_id' => $client->webhookId(),
            'currency' => $client->currency(),
            'brand' => $client->brandName(),

            /*
             * Laravel reads a cached config file instead of .env when one
             * exists. Without this flag, editing .env and seeing no change
             * is a twenty-minute mystery that ends in `optimize:clear`.
             */
            'cached' => app()->configurationIsCached(),
        ];
    }

    /**
     * The client id, shortened.
     *
     * The client id is not a secret — it is published in PayPal's own
     * JavaScript SDK — so showing it is safe, and being able to confirm
     * WHICH app is wired up is the whole point. It is still trimmed,
     * because eighty characters of base64 tell you nothing extra.
     */
    public function shortClientId(): string
    {
        $id = trim((string) config('services.paypal.client_id'));

        if ($id === '') {
            return '';
        }

        return strlen($id) <= 16 ? $id : substr($id, 0, 10).'…'.substr($id, -4);
    }

    /**
     * Mode against environment — the pairing, not the value.
     *
     * Either value alone is meaningless: sandbox is correct here and a
     * disaster in production, live is correct in production and a disaster
     * here. A pill that just says "sandbox" in orange would fire on the
     * recommended local default and teach you to stop reading it.
     */
    #[Computed]
    public function modeVerdict(): array
    {
        $live = $this->conf['mode'] === PayPalClient::LIVE;
        $production = app()->environment('production');

        return match (true) {
            $live && $production => [
                'tone' => 'success',
                'icon' => 'circle-check',
                'title' => 'Live mode on the production site.',
                'why' => 'Real payments, real account. This is the correct pairing.',
            ],
            ! $live && ! $production => [
                'tone' => 'muted',
                'icon' => 'flask',
                'title' => 'Sandbox mode on a local site.',
                'why' => 'Test money only. This is the correct pairing while building — nothing here is wrong.',
            ],
            $live && ! $production => [
                'tone' => 'danger',
                'icon' => 'triangle-exclamation',
                'title' => 'Live credentials on a local site.',
                'why' => 'Anything triggered from here moves real money in a real account, including refunds and cancellations. Set PAYPAL_MODE=sandbox unless there is a precise reason not to.',
            ],
            default => [
                'tone' => 'danger',
                'icon' => 'triangle-exclamation',
                'title' => 'Sandbox mode on the production site.',
                'why' => 'Customers can complete a checkout and nothing is ever charged. The site looks like it is selling; no money arrives.',
            ],
        };
    }

    /* ═══════════════════════════ Webhook ═══════════════════════════ */

    #[Computed]
    public function webhook(): array
    {
        // Route::has rather than a hardcoded assumption: the endpoint is the
        // next thing to be built, and a screen that prints a URL nothing
        // answers is worse than one that says it is not ready.
        $exists = Route::has('webhooks.paypal');

        $table = Schema::hasTable('webhook_events');

        return [
            'exists' => $exists,
            'url' => $exists
                ? route('webhooks.paypal')
                : rtrim((string) config('app.url'), '/').'/webhooks/paypal',
            'id_set' => $this->conf['webhook_id'] !== null,
            'table' => $table,
            'unresolved' => $table ? WebhookEvent::unresolved()->count() : 0,
            'total' => $table ? WebhookEvent::count() : 0,

            // PayPal cannot reach a .test address. Worth saying once, here,
            // rather than after an hour of webhooks that never arrive.
            'local' => str_contains((string) config('app.url'), '.test')
                || str_contains((string) config('app.url'), 'localhost'),
        ];
    }

    /* ═══════════════════════════ Plans ═══════════════════════════ */

    #[Computed]
    public function schemaReady(): bool
    {
        return Schema::hasTable('plans') && Schema::hasColumn('plans', 'paypal_plan_id');
    }

    #[Computed]
    public function plans(): \Illuminate\Support\Collection
    {
        if (! $this->schemaReady) {
            return collect();
        }

        return Plan::orderBy('sort_order')->orderBy('price_cents')->get();
    }

    /**
     * One sentence per plan about its PayPal twin.
     *
     * The three states are genuinely different and only one is a problem:
     *
     *   n/a      free, or a one-off purchase — the Orders API does not use
     *            plans at all, so null here is correct forever.
     *   missing  recurring and unsynced — nobody can subscribe.
     *   drifted  synced, but the price was edited here afterwards. This is
     *            the dangerous one: PayPal keeps billing existing
     *            subscribers at the price its plan was created with, so this
     *            screen can read $14 while every renewal collects $10.
     */
    public function planState(Plan $plan): array
    {
        if (! $plan->isRecurring()) {
            return [
                'tone' => 'muted',
                'icon' => 'minus',
                'label' => 'Not applicable',
                'why' => $plan->isFree()
                    ? 'Free — there is nothing for PayPal to charge.'
                    : 'A one-off purchase. It goes through the Orders API, which does not use plans.',
            ];
        }

        if ($plan->paypalPlanId() === null) {
            return [
                'tone' => 'warning',
                'icon' => 'circle-exclamation',
                'label' => 'Not synced',
                'why' => 'This plan does not exist in PayPal '.$this->conf['mode'].' yet, so nobody can subscribe to it.',
            ];
        }

        if ($plan->paypalPriceDrifted()) {
            return [
                'tone' => 'danger',
                'icon' => 'triangle-exclamation',
                'label' => 'Price drifted',
                'why' => 'PayPal is charging $'.number_format($plan->paypal_price_cents / 100, 2).
                    ' while this screen says $'.number_format($plan->price_cents / 100, 2).
                    '. Changing a price in PayPal means creating a new plan and migrating; it is not a field.',
            ];
        }

        return [
            'tone' => 'success',
            'icon' => 'circle-check',
            'label' => 'Synced',
            'why' => 'Matches PayPal '.$this->conf['mode'].'.',
        ];
    }

    /* ═══════════════════════════ The one action ═══════════════════════════ */

    /**
     * Ask PayPal for a token and report exactly what came back.
     *
     * This is the only thing on the screen that touches the network, and it
     * is read-only at PayPal's end: an OAuth token request creates nothing,
     * charges nothing and is safe to run against live.
     */
    public function test(): void
    {
        try {
            $this->probe = $this->client()->health() + ['at' => now()->format('H:i:s')];
        } catch (Throwable $e) {
            // health() already converts PayPal failures into a message.
            // This catches everything else — a missing cache driver, a DNS
            // resolver falling over — because a diagnostic screen that
            // crashes is the one screen that must not.
            $this->probe = [
                'ok' => false,
                'mode' => $this->conf['mode'],
                'message' => 'The check itself failed: '.$e->getMessage(),
                'at' => now()->format('H:i:s'),
            ];
        }
    }
}; ?>

<div class="space-y-5">

    {{-- ══════════════════════════════════════════════════════════════
         WHY THIS SCREEN HAS NO FIELDS
         ══════════════════════════════════════════════════════════════ --}}
    <div class="rounded-2xl border border-hairline bg-panel px-5 py-4">
        <p class="max-w-[86ch] text-[0.82rem] leading-relaxed text-paper/45">
            <x-icon name="lock-keyhole" style="solid" class="mr-1.5 text-[0.75rem] text-info" />
            Unlike Google and the captcha, PayPal credentials are <strong class="text-paper/70">not editable here</strong>
            and are not stored in the database. They live in <code class="rounded bg-raised px-1.5 py-0.5 font-mono text-[0.76rem] text-paper/70">.env</code>
            because Admin → Backups produces a downloadable dump of every table, and a live payment secret inside a file
            that gets emailed around is somebody taking money in dbelo's name. This screen shows the state and tests the
            connection; the values themselves are changed in the file.
        </p>
    </div>

    {{-- ══════════════════════════════════════════════════════════════
         CONFIG CACHE — the reason an edited .env appears to do nothing
         ══════════════════════════════════════════════════════════════ --}}
    @if ($this->conf['cached'])
        <div class="flex items-start gap-3.5 rounded-2xl border border-warning/25 bg-warning/[0.06] px-5 py-4">
            <x-admin.icon-chip icon="bolt" tone="warning" size="size-9" />
            <div class="min-w-0">
                <div class="text-[0.9rem] text-paper/85">The configuration is cached.</div>
                <p class="mt-1 max-w-[74ch] text-[0.82rem] leading-relaxed text-paper/50">
                    Laravel is reading a compiled config file, not <code class="font-mono">.env</code>. Anything edited
                    there is being ignored until the cache is cleared — which is what makes "I pasted the credentials and
                    nothing changed" so hard to diagnose.
                </p>
                <code class="mt-3 inline-block rounded-lg bg-rail px-3.5 py-2 font-mono text-[0.8rem] text-paper/80">php artisan optimize:clear</code>
            </div>
        </div>
    @endif

    {{-- ══════════════════════════════════════════════════════════════
         MODE + CREDENTIALS
         ══════════════════════════════════════════════════════════════ --}}
    <div class="grid gap-4 lg:grid-cols-2">

        {{-- Mode, judged against the app environment rather than alone. --}}
        @php $verdict = $this->modeVerdict; @endphp

        <div @class([
            'rounded-2xl border bg-panel px-5 py-4',
            'border-danger/30' => $verdict['tone'] === 'danger',
            'border-success/25' => $verdict['tone'] === 'success',
            'border-hairline' => ! in_array($verdict['tone'], ['danger', 'success'], true),
        ])>
            <div class="flex items-start gap-3.5">
                <x-admin.icon-chip :icon="$verdict['icon']" :tone="$verdict['tone']" size="size-9" />

                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="text-[0.95rem] font-medium">{{ $verdict['title'] }}</h2>

                        <span @class([
                            'rounded-full px-2 py-0.5 text-[0.66rem] uppercase tracking-[0.12em]',
                            'bg-danger/15 text-danger' => $verdict['tone'] === 'danger',
                            'bg-success/15 text-success' => $verdict['tone'] === 'success',
                            'bg-raised text-paper/45' => ! in_array($verdict['tone'], ['danger', 'success'], true),
                        ])>{{ $this->conf['mode'] }}</span>
                    </div>

                    <p class="mt-1.5 max-w-[62ch] text-[0.82rem] leading-relaxed text-paper/50">{{ $verdict['why'] }}</p>

                    <code class="mt-3 block break-all font-mono text-[0.76rem] text-paper/35">{{ $this->conf['base'] }}</code>
                </div>
            </div>
        </div>

        {{-- Credentials: present or absent, never the value. --}}
        <div class="rounded-2xl border border-hairline bg-panel px-5 py-4">
            <div class="flex items-start gap-3.5">
                <x-admin.icon-chip :icon="$this->conf['configured'] ? 'key' : 'key-skeleton'"
                                   :tone="$this->conf['configured'] ? 'success' : 'warning'" size="size-9" />

                <div class="min-w-0 flex-1">
                    <h2 class="text-[0.95rem] font-medium">
                        {{ $this->conf['configured'] ? 'Credentials are loaded.' : 'Credentials are missing.' }}
                    </h2>

                    @if ($this->conf['configured'])
                        <dl class="mt-3 space-y-2 text-[0.8rem]">
                            <div class="flex items-baseline gap-3">
                                <dt class="w-24 shrink-0 text-paper/35">Client ID</dt>
                                <dd class="min-w-0 break-all font-mono text-paper/70">{{ $this->shortClientId() }}</dd>
                            </div>
                            <div class="flex items-baseline gap-3">
                                <dt class="w-24 shrink-0 text-paper/35">Secret</dt>
                                <dd class="text-paper/70">
                                    <x-icon name="circle-check" style="solid" class="mr-1 text-[0.72rem] text-success" />
                                    stored in .env, never shown
                                </dd>
                            </div>
                            <div class="flex items-baseline gap-3">
                                <dt class="w-24 shrink-0 text-paper/35">Currency</dt>
                                <dd class="font-mono text-paper/70">{{ $this->conf['currency'] }}</dd>
                            </div>
                            <div class="flex items-baseline gap-3">
                                <dt class="w-24 shrink-0 text-paper/35">Brand</dt>
                                <dd class="text-paper/70">{{ $this->conf['brand'] }}</dd>
                            </div>
                        </dl>
                    @else
                        <p class="mt-1.5 max-w-[62ch] text-[0.82rem] leading-relaxed text-paper/50">
                            <code class="font-mono text-paper/70">PAYPAL_CLIENT_ID</code> and
                            <code class="font-mono text-paper/70">PAYPAL_CLIENT_SECRET</code> are empty. Nothing can be
                            sold until they are filled in — see the block at the bottom of this screen.
                        </p>
                    @endif

                    {{-- The only network call on the page. Read-only at PayPal's
                         end: asking for a token creates nothing and charges
                         nothing, so it is safe even against live. --}}
                    <div class="mt-4 flex flex-wrap items-center gap-3">
                        <button type="button" wire:click="test" wire:loading.attr="disabled"
                                class="inline-flex items-center gap-2 rounded-lg bg-action px-3.5 py-2 text-[0.8rem] font-medium text-white transition hover:brightness-110 disabled:opacity-50">
                            <x-icon name="plug-circle-check" style="solid" class="text-[0.78rem]" wire:loading.remove wire:target="test" />
                            <x-icon name="spinner-third" style="solid" class="animate-spin text-[0.78rem]" wire:loading wire:target="test" />
                            Test connection
                        </button>

                        @if ($probe)
                            <span class="text-[0.72rem] text-paper/25">checked {{ $probe['at'] ?? '' }}</span>
                        @endif
                    </div>

                    @if ($probe)
                        <div @class([
                            'mt-3 flex items-start gap-2.5 rounded-xl px-4 py-3',
                            'bg-success/[0.08]' => $probe['ok'],
                            'bg-danger/[0.08]' => ! $probe['ok'],
                        ])>
                            <x-icon :name="$probe['ok'] ? 'circle-check' : 'circle-xmark'" style="solid"
                                    class="mt-[0.15rem] shrink-0 text-[0.8rem] {{ $probe['ok'] ? 'text-success' : 'text-danger' }}" />
                            <p class="min-w-0 break-words text-[0.8rem] leading-relaxed text-paper/70">{{ $probe['message'] }}</p>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- ══════════════════════════════════════════════════════════════
         WEBHOOK
         ══════════════════════════════════════════════════════════════ --}}
    <div class="rounded-2xl border border-hairline bg-panel">
        <div class="border-b border-hairline px-5 py-4">
            <h2 class="text-[0.95rem] font-medium">Webhook</h2>
            <p class="mt-0.5 max-w-[80ch] text-[0.78rem] leading-relaxed text-paper/40">
                Access is granted by the webhook, not by the visitor coming back from PayPal — someone can close the tab
                before returning, or return without paying. This endpoint is the only thing that is allowed to believe a
                payment happened.
            </p>
        </div>

        <div class="space-y-4 px-5 py-4">

            {{-- The URL, selectable on click. Not a download and not a form:
                 clipboard access needs a secure context and .test is often
                 plain http, so selecting the text is the route that always
                 works and Cmd+C finishes it. --}}
            <div class="rounded-xl bg-rail px-4 py-3">
                <div class="flex items-center justify-between gap-3">
                    <div class="text-[0.68rem] uppercase tracking-[0.14em] text-paper/30">Endpoint URL</div>

                    @if (! $this->webhook['exists'])
                        <span class="rounded-full bg-warning/15 px-2 py-0.5 text-[0.66rem] uppercase tracking-[0.1em] text-warning">not built yet</span>
                    @endif
                </div>

                <code x-on:click="navigator.clipboard?.writeText($el.textContent.trim()); window.getSelection().selectAllChildren($el)"
                      class="mt-1 block cursor-pointer break-all font-mono text-[0.8rem] text-paper/75 hover:text-paper">{{ $this->webhook['url'] }}</code>

                <p class="mt-2 max-w-[76ch] text-[0.78rem] leading-relaxed text-paper/40">
                    @if ($this->webhook['exists'])
                        Register this in developer.paypal.com → your app → Webhooks, then copy the webhook ID it gives back
                        into <code class="font-mono">PAYPAL_WEBHOOK_ID</code>.
                    @else
                        The controller for this route has not been written yet, so this address currently answers 404. It is
                        shown so the shape is known, not to be registered today.
                    @endif
                </p>
            </div>

            @if ($this->webhook['local'])
                <div class="flex items-start gap-3 rounded-xl bg-raised px-4 py-3">
                    <x-icon name="tower-broadcast" style="solid" class="mt-[0.15rem] shrink-0 text-[0.8rem] text-info" />
                    <div class="min-w-0">
                        <div class="text-[0.84rem] text-paper/75">PayPal cannot reach this address.</div>
                        <p class="mt-1 max-w-[74ch] text-[0.79rem] leading-relaxed text-paper/45">
                            A <code class="font-mono">.test</code> domain only exists on this Mac. Herd ships Expose, which
                            gives a public URL that tunnels here — that is the address to register while developing.
                        </p>
                        <code class="mt-2.5 inline-block rounded-lg bg-rail px-3 py-1.5 font-mono text-[0.78rem] text-paper/80">expose share dbelo.test</code>
                    </div>
                </div>
            @endif

            <div class="grid gap-3 sm:grid-cols-2">
                {{-- Signature verification: the difference between a payment
                     system and a free-Pro button for anybody who learns the URL. --}}
                <div class="flex items-start gap-3 rounded-xl bg-raised px-4 py-3">
                    <x-admin.icon-chip :icon="$this->webhook['id_set'] ? 'shield-check' : 'shield-slash'"
                                       :tone="$this->webhook['id_set'] ? 'success' : 'warning'" size="size-8" />
                    <div class="min-w-0">
                        <div class="text-[0.84rem] text-paper/75">
                            {{ $this->webhook['id_set'] ? 'Signature verification is armed.' : 'PAYPAL_WEBHOOK_ID is not set.' }}
                        </div>
                        <p class="mt-1 text-[0.78rem] leading-relaxed text-paper/45">
                            {{ $this->webhook['id_set']
                                ? 'Incoming events are checked against PayPal before anything is granted.'
                                : 'Until it is set, every incoming event is rejected rather than trusted — an unverified endpoint is "anybody who can POST JSON gets Pro".' }}
                        </p>
                    </div>
                </div>

                {{-- The event log. --}}
                <div class="flex items-start gap-3 rounded-xl bg-raised px-4 py-3">
                    <x-admin.icon-chip :icon="$this->webhook['table'] ? 'inbox' : 'database'"
                                       :tone="$this->webhook['unresolved'] > 0 ? 'danger' : ($this->webhook['table'] ? 'muted' : 'warning')"
                                       size="size-8" />
                    <div class="min-w-0">
                        <div class="text-[0.84rem] text-paper/75">
                            @if (! $this->webhook['table'])
                                The event log table is missing.
                            @elseif ($this->webhook['unresolved'] > 0)
                                {{ $this->webhook['unresolved'] }} {{ \Illuminate\Support\Str::plural('event', $this->webhook['unresolved']) }} unresolved.
                            @else
                                {{ $this->webhook['total'] }} {{ \Illuminate\Support\Str::plural('event', $this->webhook['total']) }} recorded, none stuck.
                            @endif
                        </div>
                        <p class="mt-1 text-[0.78rem] leading-relaxed text-paper/45">
                            {{ $this->webhook['table']
                                ? 'Every delivery is written down before it is acted on, so "I paid and I do not have Pro" has an answer.'
                                : 'Run php artisan migrate — webhook_events has not been created yet.' }}
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ══════════════════════════════════════════════════════════════
         PLANS
         ══════════════════════════════════════════════════════════════ --}}
    @if (! $this->schemaReady)
        <x-admin.migration-pending what="The PayPal plan link" table="plans.paypal_plan_id" />
    @else
        <div class="rounded-2xl border border-hairline bg-panel">
            <div class="border-b border-hairline px-5 py-4">
                <h2 class="text-[0.95rem] font-medium">Plans</h2>
                <p class="mt-0.5 max-w-[80ch] text-[0.78rem] leading-relaxed text-paper/40">
                    Every recurring plan exists twice — the row here, which decides the download quota and premium access,
                    and a plan inside PayPal, which decides what is actually charged. These two can disagree, and this is
                    the only place that says so.
                </p>
            </div>

            <div class="divide-y divide-hairline">
                @forelse ($this->plans as $plan)
                    @php $state = $this->planState($plan); @endphp

                    <div class="flex flex-wrap items-start gap-x-4 gap-y-3 px-5 py-4" wire:key="plan-{{ $plan->id }}">
                        <x-admin.icon-chip :icon="$state['icon']" :tone="$state['tone']" size="size-8" />

                        <div class="min-w-0 flex-1 basis-64">
                            <div class="flex flex-wrap items-center gap-2">
                                <a href="{{ route('admin.plans') }}" wire:navigate
                                   class="text-[0.9rem] text-paper/85 hover:text-paper hover:underline underline-offset-2">{{ $plan->name }}</a>

                                <span class="font-mono text-[0.74rem] text-paper/30">{{ $plan->priceForHumans() }}{{ $plan->isFree() ? '' : ' / '.$plan->interval }}</span>

                                @unless ($plan->is_active)
                                    <span class="rounded-full bg-raised px-2 py-0.5 text-[0.64rem] uppercase tracking-[0.1em] text-paper/35">inactive</span>
                                @endunless
                            </div>

                            <p class="mt-1 max-w-[70ch] text-[0.79rem] leading-relaxed text-paper/45">{{ $state['why'] }}</p>

                            @if ($plan->paypalPlanId())
                                <code class="mt-1.5 block break-all font-mono text-[0.72rem] text-paper/30">{{ $plan->paypalPlanId() }}</code>
                            @endif
                        </div>

                        <span @class([
                            'shrink-0 rounded-full px-2.5 py-1 text-[0.68rem] uppercase tracking-[0.1em]',
                            'bg-success/15 text-success' => $state['tone'] === 'success',
                            'bg-warning/15 text-warning' => $state['tone'] === 'warning',
                            'bg-danger/15 text-danger' => $state['tone'] === 'danger',
                            'bg-raised text-paper/35' => $state['tone'] === 'muted',
                        ])>{{ $state['label'] }}</span>
                    </div>
                @empty
                    <div class="px-5 py-8 text-center text-[0.84rem] text-paper/35">
                        No plans yet — run <code class="font-mono text-paper/60">php artisan db:seed --class=PlanSeeder</code>.
                    </div>
                @endforelse
            </div>

            {{-- No "Sync with PayPal" button on purpose: the command that
                 would back it does not exist yet, and a button that does
                 nothing is worse than no button — it gets clicked, it gets
                 trusted, and the plan is assumed synced. --}}
            <div class="border-t border-hairline px-5 py-3.5">
                <p class="text-[0.78rem] leading-relaxed text-paper/35">
                    <x-icon name="circle-info" style="regular" class="mr-1 text-[0.74rem]" />
                    Syncing is not wired up yet. When it is, it arrives as
                    <code class="font-mono text-paper/55">php artisan paypal:sync-plans</code> and a button here.
                </p>
            </div>
        </div>
    @endif

    {{-- ══════════════════════════════════════════════════════════════
         THE .env BLOCK — so this is never looked up in a chat log again
         ══════════════════════════════════════════════════════════════ --}}
    <div class="rounded-2xl border border-hairline bg-panel">
        <div class="border-b border-hairline px-5 py-4">
            <h2 class="text-[0.95rem] font-medium">Where the values go</h2>
            <p class="mt-0.5 max-w-[80ch] text-[0.78rem] leading-relaxed text-paper/40">
                In <code class="font-mono text-paper/60">.env</code>, at the project root. Sandbox and live are separate
                PayPal apps with separate credentials — they are not interchangeable, and a live key in sandbox mode
                simply fails to authenticate, which is the safe direction.
            </p>
        </div>

        <div class="px-5 py-4">
            <pre x-on:click="window.getSelection().selectAllChildren($el)"
                 class="cursor-pointer overflow-x-auto rounded-xl bg-rail px-4 py-3.5 font-mono text-[0.78rem] leading-relaxed text-paper/70"># developer.paypal.com → Apps &amp; Credentials → Sandbox → Create App
PAYPAL_MODE=sandbox
PAYPAL_CLIENT_ID=
PAYPAL_CLIENT_SECRET=
PAYPAL_WEBHOOK_ID=
PAYPAL_BRAND_NAME="dbelo"
PAYPAL_CURRENCY=USD</pre>

            <p class="mt-3 max-w-[80ch] text-[0.78rem] leading-relaxed text-paper/40">
                <strong class="text-warning/90">PAYPAL_MODE only accepts <code class="font-mono">live</code> exactly.</strong>
                <code class="font-mono">Live</code>, <code class="font-mono">prod</code> or <code class="font-mono">1</code>
                are all treated as sandbox — a typo must never be the reason a real card is charged.
                After editing, run <code class="font-mono text-paper/60">php artisan optimize:clear</code>.
            </p>
        </div>
    </div>
</div>
