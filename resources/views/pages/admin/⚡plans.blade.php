<?php

use App\Models\ActivityLog;
use App\Models\Plan;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The plans, and what each one is allowed to do.
 *
 * ── THIS SCREEN EDITS, IT DOES NOT CREATE ────────────────────────────────
 *
 * New plans come from database/seeders/PlanSeeder.php and nowhere else, and
 * that is deliberate. A plan is not just a row here: PayPal keeps its own
 * copy, with its own id, and the two have to be created together. A button
 * that made a row this side only would produce a plan nobody can pay for —
 * visible, priced, and dead on click.
 *
 * Editing is different. A limit, a name, a feature line: those live only
 * here and change nothing at PayPal. Which is exactly why the price is
 * treated as a special case below.
 *
 * ── THE THREE THINGS THAT CAN GO WRONG, AND WHAT STOPS THEM ──────────────
 *
 * 1. The `free` slug is load-bearing. User::currentPlan() looks it up by
 *    that exact string and canDownload() returns false without a plan — so
 *    renaming or deactivating it silently blocks every download for every
 *    REGISTERED user, while anonymous visitors carry on through a different
 *    path. Signing up would make the site worse and nothing would error.
 *    The slug is not editable anywhere, and the free plan cannot be
 *    switched off here.
 *
 * 2. Changing a price after somebody is paying it does NOT reach PayPal. A
 *    PayPal plan with active subscribers cannot be freely repriced; the
 *    move is a new plan and a migration. So this screen counts the active
 *    subscribers on each plan and says so, loudly, next to the price field.
 *
 * 3. "Unlimited" is a NULL, not a zero. canDownload() reads a null limit as
 *    no ceiling; a zero would mean nobody downloads anything, which is a
 *    very different product for the same money. The form never shows an
 *    empty number box — it shows a switch, and the switch writes the null.
 */
new #[Layout('layouts.admin')] #[Title('Plans')] class extends Component {
    /** The plan being edited, by id. One at a time, on purpose. */
    public ?int $editing = null;

    /** @var array<string, mixed> */
    public array $form = [];

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    #[Computed]
    public function ready(): bool
    {
        return Schema::hasTable('plans');
    }

    /**
     * Every plan, with how many people are actually paying for it.
     *
     * The count uses Subscription::active(), the same scope the access check
     * uses — so the number beside the price is the number of people a price
     * change would affect, not the number of rows that ever existed.
     */
    #[Computed]
    public function plans()
    {
        return Plan::query()
            ->withCount(['subscriptions as active_count' => fn ($q) => $q->active()])
            ->orderBy('sort_order')
            ->orderBy('price_cents')
            ->get();
    }

    /** Is the row every registered user falls back to actually there? */
    #[Computed]
    public function freeMissing(): bool
    {
        return ! $this->plans->contains(fn ($p) => $p->slug === 'free');
    }

    public function edit(int $id): void
    {
        $plan = $this->plans->firstWhere('id', $id);

        if (! $plan) {
            return;
        }

        $this->editing = $id;

        $this->form = [
            'name' => (string) $plan->name,
            'description' => (string) $plan->description,
            // Edited in dollars because that is what the price IS to
            // everybody who talks about it. Cents are a storage detail and
            // asking somebody to type 1000 for ten dollars is how a plan
            // ends up costing a hundred.
            'price' => number_format($plan->price_cents / 100, 2, '.', ''),
            'interval' => (string) $plan->interval,
            'unlimited' => $plan->daily_download_limit === null,
            'daily_download_limit' => (string) ($plan->daily_download_limit ?? 50),
            'allows_premium' => (bool) $plan->allows_premium,
            'is_active' => (bool) $plan->is_active,
            'sort_order' => (string) $plan->sort_order,
            'features' => implode("\n", (array) ($plan->features ?? [])),
        ];
    }

    public function cancel(): void
    {
        $this->editing = null;
        $this->form = [];
        $this->resetErrorBag();
    }

    /* ═════════════════ Does this still leave a business? ═════════════════ */

    /*
     * THESE ARE WARNINGS, NOT VALIDATION. Every one of them can be ignored
     * and the form still saves — because "unprofitable" is a judgement about
     * a business, not a fact about a number, and a screen that refuses to
     * save a price because it disagrees with you is a screen you start
     * fighting. Validation blocks what is impossible; this says what is
     * probably a mistake and leaves the decision where it belongs.
     *
     * The thresholds below are reasoning, not taste. Each one is a sentence
     * somebody could argue with, which is the point.
     */

    /*
     * Calibrated so the RECOMMENDED setting does not set off an alarm.
     *
     * The first version put the danger line at 300 a month, which is exactly
     * 10 a day — the number this project actually ships with. A screen that
     * shouts at its own default teaches you to stop reading it, and then it
     * cannot warn you about anything.
     *
     * Below 200 a month a working week genuinely runs out. Between 200 and
     * 1,000 most people never reach the ceiling, which is worth saying but
     * is a judgement, not a fault. Past 1,000 free IS the product.
     */
    private const FREE_COMFORTABLE = 200;

    private const FREE_UNSELLABLE = 1000;

    /** Below this the flat $0.49 PayPal fee eats more than a seventh of the sale. */
    private const PRICE_FEE_FLOOR = 4.0;

    /** An annual saving less than this does not move anybody off monthly. */
    private const ANNUAL_MIN_DISCOUNT = 0.25;

    /** A premium catalogue takeable faster than this is a catalogue you gave away. */
    private const DRAIN_DAYS = 7;

    /** The download rate limiter, from AppServiceProvider. 200/hour. */
    private const RATE_PER_DAY = 4800;

    /**
     * What PayPal actually leaves you, at the international rate.
     *
     * 3.49% + $0.49 in the US, plus 1.5% cross-border. The international rate
     * is the honest default for a library sold worldwide — assuming the
     * cheaper domestic one would flatter every number on this screen.
     */
    private static function net(float $price): float
    {
        return $price <= 0 ? 0.0 : $price - ($price * 0.0499 + 0.49);
    }

    /** How many premium sounds there are to take. Read, not assumed. */
    #[Computed]
    public function premiumCount(): int
    {
        return Schema::hasTable('sounds')
            ? \App\Models\Sound::published()->where('is_premium', true)->count()
            : 0;
    }

    /**
     * Advice for the plan being edited, keyed by the field it sits under.
     *
     * @return array<string, array<int, array{level: string, text: string}>>
     */
    #[Computed]
    public function advice(): array
    {
        $out = ['price' => [], 'limit' => []];

        if ($this->editing === null) {
            return $out;
        }

        $plan = $this->plans->firstWhere('id', $this->editing);
        $price = (float) ($this->form['price'] ?? 0);
        $unlimited = (bool) ($this->form['unlimited'] ?? false);
        $limit = (int) ($this->form['daily_download_limit'] ?? 0);
        $interval = (string) ($this->form['interval'] ?? 'month');
        $isFree = $plan?->slug === 'free';
        $premium = $this->premiumCount;

        $say = function (string $field, string $level, string $text) use (&$out) {
            $out[$field][] = ['level' => $level, 'text' => $text];
        };

        /* ── The free plan's ceiling ─────────────────────────────────────── */
        if ($isFree) {
            if ($unlimited) {
                $say('limit', 'danger', 'Unlimited free downloads. There is no version of this that sells a subscription — whatever Pro offers, this already gives away.');
            } elseif ($limit === 0) {
                $say('limit', 'danger', 'Zero means signing up gives a person nothing at all. Anonymous visitors would be better off than registered ones.');
            } else {
                // The limit is per DAY — canDownload() compares it against
                // downloadsToday() — so a monthly figure is the honest way to
                // read it, and it is usually the number that surprises people.
                $month = $limit * 30;

                if ($month >= self::FREE_UNSELLABLE) {
                    $say('limit', 'danger', "{$limit} a day is about {$month} a month. Nobody reaches that, so \"unlimited downloads\" stops being a reason to pay and Pro is left selling premium sounds and nothing else.");
                } elseif ($month >= self::FREE_COMFORTABLE) {
                    $say('limit', 'warning', "{$limit} a day is about {$month} a month, and a real project uses 5–20 sounds. Most people will never hit this, so the moment where somebody decides to pay never arrives.");
                } else {
                    $say('limit', 'ok', "{$limit} a day is about {$month} a month — enough for a small project, not enough for a working week. This is the range where people upgrade.");
                }
            }
        }

        /* ── A paid plan that gives no more than free ───────────────────── */
        if (! $isFree && ! $unlimited && $limit > 0) {
            $free = $this->plans->firstWhere('slug', 'free');

            if ($free && $free->daily_download_limit !== null && $limit <= $free->daily_download_limit) {
                $say('limit', 'danger', "Free already allows {$free->daily_download_limit} a day. A paid plan at or below that is asking for money in exchange for nothing.");
            }
        }

        /* ── How fast the premium catalogue walks out ───────────────────── */
        if (! $isFree && $premium > 0 && ($this->form['allows_premium'] ?? false)) {
            $perDay = $unlimited ? self::RATE_PER_DAY : $limit;

            if ($perDay > 0) {
                $days = (int) ceil($premium / $perDay);

                if ($days < self::DRAIN_DAYS) {
                    $hours = $unlimited ? round($premium / 200, 1) : null;

                    $say('limit', 'warning', $unlimited
                        ? "You have {$premium} premium sounds. With no cap, the rate limiter still allows 200 an hour — one subscriber can take all of them in about {$hours} hours and has no reason to renew. A high cap like 100 a day reads as unlimited to every real user and turns that afternoon into weeks."
                        : "You have {$premium} premium sounds. At {$limit} a day somebody clears the whole premium catalogue in {$days} days.");
                }
            }
        }

        /* ── What PayPal leaves ─────────────────────────────────────────── */
        if (! $isFree && $price > 0) {
            $net = self::net($price);
            $cut = (1 - $net / $price) * 100;

            if ($price < self::PRICE_FEE_FLOOR) {
                $say('price', 'danger', sprintf('PayPal keeps %.0f%% of this — $%.2f of $%.2f, mostly the flat $0.49. Below four dollars the fee stops being a cost and starts being a partner.', $cut, $price - $net, $price));
            } else {
                $say('price', 'ok', sprintf('PayPal keeps $%.2f (%.1f%%). You net $%.2f a sale.', $price - $net, $cut, $net));
            }
        }

        /* ── Annual against monthly ─────────────────────────────────────── */
        if ($interval === 'year' && $price > 0) {
            $monthly = $this->plans->first(fn ($p) => $p->interval === 'month' && $p->price_cents > 0);

            if ($monthly) {
                $year = $monthly->price_cents / 100 * 12;
                $discount = $year > 0 ? 1 - $price / $year : 0;
                $pct = round($discount * 100);

                if ($discount < self::ANNUAL_MIN_DISCOUNT) {
                    $say('price', 'warning', sprintf('Only %d%% below twelve months of %s ($%.2f). A subscriber who churns in two or three months is worth a fraction of one who pays a year up front, and under about 25%% off most people simply pick monthly.', $pct, $monthly->name, $year));
                } else {
                    $say('price', 'ok', sprintf('%d%% below twelve months of %s. That is the gap that actually moves people onto the annual — which is worth several times a monthly subscriber.', $pct, $monthly->name));
                }
            }
        }

        return $out;
    }

    public function save(): void
    {
        $plan = Plan::find($this->editing);

        if (! $plan) {
            $this->cancel();

            return;
        }

        $this->validate([
            'form.name' => ['required', 'string', 'max:60'],
            'form.description' => ['nullable', 'string', 'max:200'],
            'form.price' => ['required', 'numeric', 'min:0', 'max:9999'],
            'form.interval' => ['required', 'in:day,week,month,year'],
            // Only read when `unlimited` is off, but validated always so a
            // stray value cannot survive a toggle and land in the database.
            'form.daily_download_limit' => ['required_if:form.unlimited,false', 'nullable', 'integer', 'min:1', 'max:10000'],
            'form.sort_order' => ['required', 'integer', 'min:0', 'max:99'],
            'form.features' => ['nullable', 'string', 'max:1000'],
        ], [
            'form.price.numeric' => 'A price in dollars, like 7 or 9.50.',
            'form.daily_download_limit.min' => 'At least one, or switch on unlimited. Zero would sell a plan that downloads nothing.',
        ]);

        $isFree = $plan->slug === 'free';

        $changes = [
            'name' => trim($this->form['name']),
            'description' => trim((string) $this->form['description']) ?: null,
            // round(), not (int): 7.99 * 100 is 798.9999… in binary floating
            // point, and casting truncates it to 798. A cent lost on every
            // price ending in 99 is the kind of bug nobody reports.
            'price_cents' => $isFree ? 0 : (int) round(((float) $this->form['price']) * 100),
            'interval' => $this->form['interval'],
            'daily_download_limit' => $this->form['unlimited'] ? null : (int) $this->form['daily_download_limit'],
            'allows_premium' => (bool) $this->form['allows_premium'],
            // The free row is the fallback for every registered user. It
            // does not get to be switched off from a checkbox.
            'is_active' => $isFree ? true : (bool) $this->form['is_active'],
            'sort_order' => (int) $this->form['sort_order'],
            'features' => array_values(array_filter(array_map(
                'trim',
                preg_split('/\r\n|\r|\n/', (string) $this->form['features']) ?: [],
            ), fn ($line) => $line !== '')),
        ];

        $before = $plan->only(array_keys($changes));

        $plan->update($changes);

        // Recorded with both sides. A price is the one field somebody will
        // later need to prove was a certain value on a certain day.
        ActivityLog::record('plans.updated', null,
            'Plan — '.$plan->name.' updated',
            ['plan' => $plan->slug, 'from' => $before, 'to' => $changes]);

        unset($this->plans);

        $this->cancel();

        session()->flash('ok', 'Saved.');
    }
}; ?>

<div class="space-y-5">

    @if (! $this->ready)
        <x-admin.migration-pending what="Plans" table="plans" />
    @else

        @if (session('ok'))
            <div class="rounded-2xl border border-success/25 bg-success/[0.06] px-5 py-3.5 text-[0.86rem] text-paper/75">
                <x-icon name="circle-check" style="solid" class="mr-1.5 text-[0.8rem] text-success" />
                {{ session('ok') }}
            </div>
        @endif

        {{-- ══════════════════════════════════════════════════════════════
             THE ONE THAT BREAKS DOWNLOADS SILENTLY
             ══════════════════════════════════════════════════════════════ --}}
        @if ($this->freeMissing)
            <div class="rounded-2xl border border-danger/30 bg-danger/[0.07] px-5 py-4">
                <div class="flex items-start gap-3.5">
                    <x-icon name="triangle-exclamation" style="solid" class="mt-[0.2rem] shrink-0 text-[0.9rem] text-danger" />
                    <div class="min-w-0">
                        <div class="text-[0.9rem] text-paper/85">There is no plan with the slug <span class="font-mono">free</span>.</div>
                        <p class="mt-1 max-w-[80ch] text-[0.82rem] leading-relaxed text-paper/50">
                            Every registered user without a subscription falls back to it, and the download check refuses
                            when there is no plan at all. Right now <strong>signing up makes the site worse</strong>: anonymous
                            visitors download normally, and anybody with an account is refused — with nothing in the logs.
                            Run <span class="font-mono text-paper/70">php artisan db:seed --class=PlanSeeder</span>.
                        </p>
                    </div>
                </div>
            </div>
        @endif

        {{-- ══════════════════════════════════════════════════════════════
             THE PLANS
             ══════════════════════════════════════════════════════════════ --}}
        @foreach ($this->plans as $plan)
            @php
                $isFree = $plan->slug === 'free';
                $paying = (int) $plan->active_count;
            @endphp

            <div class="rounded-2xl border border-hairline bg-panel" wire:key="plan-{{ $plan->id }}">

                {{-- ── Header ── --}}
                <div class="flex flex-wrap items-center gap-3 border-b border-hairline px-5 py-4">
                    <x-admin.icon-chip :icon="$isFree ? 'gift' : 'credit-card'" :tone="$isFree ? 'muted' : 'brand'" />

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-[0.95rem] font-medium">{{ $plan->name }}</span>

                            <span class="rounded-full bg-raised px-2 py-0.5 font-mono text-[0.68rem] text-paper/40">{{ $plan->slug }}</span>

                            @unless ($plan->is_active)
                                <span class="rounded-full bg-warning/15 px-2 py-0.5 text-[0.68rem] font-medium text-warning">Hidden</span>
                            @endunless
                        </div>

                        <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-[0.78rem] text-paper/40">
                            <span class="text-paper/70">
                                {{ $plan->priceForHumans() }}@unless ($plan->isFree()) <span class="text-paper/35">/ {{ $plan->interval }}</span>@endunless
                            </span>

                            <span>·</span>

                            <span>{{ $plan->isUnlimited() ? 'Unlimited downloads' : $plan->daily_download_limit.' a day' }}</span>

                            <span>·</span>

                            <span>{{ $plan->allows_premium ? 'Premium included' : 'Standard sounds' }}</span>
                        </div>
                    </div>

                    {{-- The number that decides whether the price is still
                         yours to change. --}}
                    <div class="shrink-0 text-right">
                        <div class="text-[1.05rem] font-medium tabular-nums {{ $paying > 0 ? 'text-paper/80' : 'text-paper/25' }}">{{ $paying }}</div>
                        <div class="text-[0.68rem] uppercase tracking-[0.14em] text-paper/30">paying</div>
                    </div>

                    @if ($editing !== $plan->id)
                        <x-admin.icon-button icon="pen" variant="ghost" label="Edit" wire:click="edit({{ $plan->id }})" />
                    @endif
                </div>

                {{-- ── The form ── --}}
                @if ($editing === $plan->id)
                    <form wire:submit="save" class="space-y-5 px-5 py-5">

                        @if ($paying > 0)
                            {{-- Said here, beside the field, not in a manual.
                                 This is the moment the mistake is made. --}}
                            <div class="rounded-xl border border-warning/25 bg-warning/[0.07] px-4 py-3.5">
                                <div class="flex items-start gap-3">
                                    <x-icon name="triangle-exclamation" style="solid" class="mt-[0.15rem] shrink-0 text-[0.82rem] text-warning" />
                                    <p class="max-w-[80ch] text-[0.8rem] leading-relaxed text-paper/55">
                                        <strong class="text-paper/80">{{ $paying }} {{ $paying === 1 ? 'person is' : 'people are' }} paying for this plan.</strong>
                                        Changing the price here does <em>not</em> change what PayPal charges them — PayPal keeps
                                        its own copy, and a plan with subscribers cannot be repriced. They keep paying the old
                                        amount while this screen shows the new one. To actually change a price, create a new
                                        plan and move people to it. Limits, names and feature lines are safe to edit: those
                                        live only here.
                                    </p>
                                </div>
                            </div>
                        @endif


                        @php
                            $adviceFor = fn ($field) => $this->advice[$field] ?? [];
                            $adviceTone = [
                                'danger' => 'border-danger/25 bg-danger/[0.07] text-danger',
                                'warning' => 'border-warning/25 bg-warning/[0.07] text-warning',
                                'ok' => 'border-hairline bg-raised/40 text-paper/45',
                            ];
                        @endphp

                        <div class="grid gap-5 sm:grid-cols-2">
                            {{-- Name --}}
                            <label class="block">
                                <span class="mb-1.5 block text-[0.78rem] text-paper/50">Name</span>
                                <input type="text" wire:model="form.name" maxlength="60"
                                       class="w-full rounded-lg border-0 bg-raised px-3 py-2.5 text-[0.86rem] focus:outline-none focus:ring-1 focus:ring-brand" />
                                @error('form.name') <span class="mt-1 block text-[0.75rem] text-danger">{{ $message }}</span> @enderror
                            </label>

                            {{-- Price + interval --}}
                            <div>
                                <span class="mb-1.5 block text-[0.78rem] text-paper/50">
                                    Price @if ($isFree) <span class="text-paper/30">— the free plan is always $0</span> @endif
                                </span>

                                <div class="flex items-center gap-2">
                                    <div class="flex items-center gap-1.5 rounded-lg bg-raised px-3 py-2.5 {{ $isFree ? 'opacity-40' : '' }}">
                                        <span class="text-[0.86rem] text-paper/40">$</span>
                                        <input type="text" wire:model.live.debounce.400ms="form.price" inputmode="decimal" @disabled($isFree)
                                               class="w-20 border-0 bg-transparent p-0 text-[0.86rem] tabular-nums focus:outline-none focus:ring-0" />
                                    </div>

                                    <select wire:model.live="form.interval" @disabled($isFree)
                                            class="rounded-lg border-0 bg-raised px-3 py-2.5 text-[0.84rem] focus:outline-none focus:ring-1 focus:ring-brand {{ $isFree ? 'opacity-40' : '' }}">
                                        <option value="day">per day</option>
                                        <option value="week">per week</option>
                                        <option value="month">per month</option>
                                        <option value="year">per year</option>
                                    </select>
                                </div>

                                @error('form.price') <span class="mt-1 block text-[0.75rem] text-danger">{{ $message }}</span> @enderror

                                {{-- Advice, not validation. Every one of these
                                     can be ignored and the form still saves. --}}
                                @foreach ($adviceFor('price') as $note)
                                    <p class="mt-2 rounded-lg border px-3 py-2 text-[0.75rem] leading-relaxed {{ $adviceTone[$note['level']] }}"
                                       wire:key="adv-price-{{ $loop->index }}">
                                        <x-icon :name="$note['level'] === 'ok' ? 'circle-check' : 'triangle-exclamation'" style="solid" class="mr-1 text-[0.68rem]" />
                                        {{ $note['text'] }}
                                    </p>
                                @endforeach
                            </div>
                        </div>

                        {{-- Description --}}
                        <label class="block">
                            <span class="mb-1.5 block text-[0.78rem] text-paper/50">Description</span>
                            <input type="text" wire:model="form.description" maxlength="200"
                                   class="w-full rounded-lg border-0 bg-raised px-3 py-2.5 text-[0.86rem] focus:outline-none focus:ring-1 focus:ring-brand" />
                        </label>

                        {{-- ── The limit ──
                             A switch and a number, not a number that means
                             something else when empty. --}}
                        <div class="rounded-xl border border-hairline bg-raised/40 px-4 py-4">
                            <div class="flex flex-wrap items-center gap-4">
                                <label class="flex cursor-pointer items-center gap-2.5">
                                    <input type="checkbox" wire:model.live="form.unlimited"
                                           class="size-4 rounded border-0 bg-raised text-brand focus:ring-2 focus:ring-brand/40" />
                                    <span class="text-[0.86rem]">Unlimited downloads</span>
                                </label>

                                @unless ($form['unlimited'] ?? false)
                                    <label class="flex items-center gap-2.5">
                                        <span class="text-[0.82rem] text-paper/50">Downloads a day</span>
                                        <input type="number" wire:model.live.debounce.400ms="form.daily_download_limit" min="1" max="10000"
                                               class="w-24 rounded-lg border-0 bg-raised px-3 py-2 text-[0.86rem] tabular-nums focus:outline-none focus:ring-1 focus:ring-brand" />
                                    </label>
                                @endunless
                            </div>

                            <p class="mt-2.5 max-w-[80ch] text-[0.75rem] leading-relaxed text-paper/35">
                                Counted per account per day, from midnight — not from when the plan was bought. On a
                                day pass that means somebody buying late in the evening gets the allowance twice: once
                                before midnight and once after. Worth knowing before pricing a short pass low.
                            </p>

                            @error('form.daily_download_limit') <span class="mt-1 block text-[0.75rem] text-danger">{{ $message }}</span> @enderror

                            @foreach ($adviceFor('limit') as $note)
                                <p class="mt-2 rounded-lg border px-3 py-2 text-[0.75rem] leading-relaxed {{ $adviceTone[$note['level']] }}"
                                   wire:key="adv-limit-{{ $loop->index }}">
                                    <x-icon :name="$note['level'] === 'ok' ? 'circle-check' : 'triangle-exclamation'" style="solid" class="mr-1 text-[0.68rem]" />
                                    {{ $note['text'] }}
                                </p>
                            @endforeach
                        </div>

                        {{-- Switches --}}
                        <div class="flex flex-wrap items-center gap-6">
                            <label class="flex cursor-pointer items-center gap-2.5">
                                <input type="checkbox" wire:model.live="form.allows_premium"
                                       class="size-4 rounded border-0 bg-raised text-brand focus:ring-2 focus:ring-brand/40" />
                                <span class="text-[0.86rem]">Includes premium sounds</span>
                            </label>

                            <label class="flex items-center gap-2.5 {{ $isFree ? 'cursor-not-allowed opacity-40' : 'cursor-pointer' }}">
                                <input type="checkbox" wire:model="form.is_active" @disabled($isFree)
                                       class="size-4 rounded border-0 bg-raised text-brand focus:ring-2 focus:ring-brand/40" />
                                <span class="text-[0.86rem]">
                                    Shown on the pricing page
                                    @if ($isFree) <span class="text-paper/40">— the fallback plan cannot be hidden</span> @endif
                                </span>
                            </label>

                            <label class="flex items-center gap-2.5">
                                <span class="text-[0.82rem] text-paper/50">Order</span>
                                <input type="number" wire:model="form.sort_order" min="0" max="99"
                                       class="w-16 rounded-lg border-0 bg-raised px-3 py-2 text-[0.86rem] tabular-nums focus:outline-none focus:ring-1 focus:ring-brand" />
                            </label>
                        </div>

                        {{-- Features --}}
                        <label class="block">
                            <span class="mb-1.5 block text-[0.78rem] text-paper/50">
                                Feature lines <span class="text-paper/30">— one per line, shown on the pricing page</span>
                            </span>
                            <textarea wire:model="form.features" rows="5"
                                      class="w-full rounded-lg border-0 bg-raised px-3 py-2.5 text-[0.86rem] leading-relaxed focus:outline-none focus:ring-1 focus:ring-brand"></textarea>
                            <span class="mt-1.5 block max-w-[80ch] text-[0.75rem] leading-relaxed text-paper/35">
                                Only write what the code enforces: the daily limit, premium access, and ads. Nothing here
                                serves a different file format or a different licence by plan — promising either is a
                                refund request with a countdown on it.
                            </span>
                        </label>

                        <div class="flex items-center gap-3 border-t border-hairline pt-4">
                            <button type="submit"
                                    class="rounded-lg bg-action px-4 py-2 text-[0.85rem] font-medium text-white transition hover:opacity-90">
                                Save
                            </button>

                            <button type="button" wire:click="cancel"
                                    class="text-[0.82rem] text-paper/40 transition hover:text-paper">
                                Cancel
                            </button>
                        </div>
                    </form>
                @endif
            </div>
        @endforeach

        {{-- ══════════════════════════════════════════════════════════════
             WHY THERE IS NO "NEW PLAN" BUTTON
             ══════════════════════════════════════════════════════════════ --}}
        <div class="rounded-2xl border border-hairline bg-panel px-5 py-4">
            <div class="text-[0.9rem] text-paper/70">Adding a plan is a two-sided job</div>
            <p class="mt-1.5 max-w-[85ch] text-[0.8rem] leading-relaxed text-paper/40">
                A plan exists twice: as a row here, and as a plan inside PayPal with its own id. A button that made
                only the row would produce a plan people can see, compare and click — and cannot pay for. New plans
                come from <span class="font-mono text-paper/60">database/seeders/PlanSeeder.php</span>, so the price a
                plan launched with is in version control, and then get their PayPal id from
                <span class="font-mono text-paper/60">paypal:sync-plans</span> once that command exists.
            </p>
        </div>
    @endif
</div>
