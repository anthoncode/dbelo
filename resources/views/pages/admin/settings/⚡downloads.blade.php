<?php

use App\Models\ActivityLog;
use App\Models\Plan;
use App\Models\Setting;
use App\Support\Downloads;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The smallest settings screen in the panel, and that is the point.
 *
 * The first draft of it had eight fields: daily limits, a link lifetime, a
 * re-download window, a filename pattern, whether the licence goes in the
 * zip. Every one of them was wrong in the same way — it either duplicated
 * something that already had a home, or it configured something that did
 * not exist:
 *
 *   Daily limits belong on the PLAN. That is what a plan is. A second copy
 *   here would eventually disagree with the plans table, and the version
 *   somebody read would be whichever screen they opened.
 *
 *   A link lifetime would have configured nothing: downloads go through a
 *   controller, not a signed URL. A field with no consumer is a control
 *   that lies.
 *
 *   The re-download window is a bug fix with one right answer, so it is a
 *   constant. Nobody should have to decide it.
 *
 * What is left is one genuine decision — how much somebody gets before we
 * ask who they are — and the two lines of wording around it.
 */
new #[Layout('layouts.admin')] #[Title('Downloads')] class extends Component {
    /** @var array<string, string> */
    public array $values = [];

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $stored = Setting::cached();

        foreach (Downloads::FIELDS as $field => $meta) {
            $saved = (string) ($stored[$meta['key']] ?? '');

            $this->values[$field] = $meta['type'] === 'bool' && $saved === ''
                ? Downloads::defaultValue($field)
                : $saved;
        }
    }

    public function save(): void
    {
        $this->validate([
            // Capped at 25 rather than left open. A number high enough that
            // the catalogue is usable without an account is not a free
            // allowance, it is the site being free — which is a business
            // decision, not a field.
            'values.guest_limit' => ['nullable', 'integer', 'between:1,25'],
            'values.guest_message' => ['nullable', 'string', 'max:300'],
        ], [], [
            'values.guest_limit' => 'free downloads',
            'values.guest_message' => 'message',
        ]);

        $before = Setting::cached();
        $changed = 0;

        foreach (Downloads::FIELDS as $field => $meta) {
            $new = trim((string) ($this->values[$field] ?? ''));
            $old = (string) ($before[$meta['key']] ?? '');

            // Storing a value identical to the config default writes a row
            // that changes nothing and an audit entry about it.
            if ($new === Downloads::defaultValue($field)) {
                $new = '';
            }

            if ($new === $old) {
                continue;
            }

            Setting::put($meta['key'], $new === '' ? null : $new, 'downloads');

            ActivityLog::record('settings.updated', null,
                'Downloads — '.$meta['label'].($new === '' ? ' reset' : ' changed'),
                ['setting' => $meta['key'], 'from' => $old ?: null, 'to' => $new ?: null]);

            $changed++;
        }

        $this->dispatch('saved');

        session()->flash('ok', match ($changed) {
            0 => 'Nothing had changed.',
            1 => 'Saved.',
            default => "Saved — {$changed} changes.",
        });
    }

    public function setFlag(string $field, string $value): void
    {
        if (array_key_exists($field, Downloads::FIELDS) && in_array($value, ['0', '1'], true)) {
            $this->values[$field] = $value;
        }
    }

    /* ═════════════════════════════ Helpers ═════════════════════════════ */

    #[Computed]
    public function ready(): bool
    {
        return Schema::hasTable('settings');
    }

    public function fallback(string $field): string
    {
        $value = config('dbelo.'.Downloads::FIELDS[$field]['key']);

        return is_bool($value) ? ($value ? 'on' : 'off') : (string) $value;
    }

    /**
     * What somebody who is not signed in gets right now, in one sentence.
     *
     * Reading the form rather than the stored settings on purpose: this
     * strip has to answer "what am I about to switch this to", which is the
     * question being asked while the switch is under the cursor.
     */
    #[Computed]
    public function state(): array
    {
        if (($this->values['guest_enabled'] ?? '0') !== '1') {
            return [
                'tone' => 'closed',
                'icon' => 'lock',
                'headline' => 'An account before any file',
                'detail' => 'A visitor can play the whole catalogue and download none of it. Every download button sends them to the sign-up page instead.',
            ];
        }

        $n = max(1, (int) ($this->values['guest_limit'] ?: $this->fallback('guest_limit')));

        return [
            'tone' => 'open',
            'icon' => 'unlock',
            'headline' => $n === 1
                ? 'One file, then sign up'
                : $n.' files, then sign up',
            // No ordinals. "the 3th time" is what you get from gluing 'th'
            // onto a number, and this sentence is the one an operator reads
            // to decide whether the switch does what they think.
            'detail' => 'A visitor can take '.($n === 1 ? 'one sound' : $n.' sounds').' without an account. '
                .'Premium sounds are excluded, re-downloading one they already took costs nothing, and the '
                .'next press after that lands them on the sign-up page rather than an error.',
        ];
    }

    /** The plans the daily limits actually live on. */
    #[Computed]
    public function plans()
    {
        if (! Schema::hasTable('plans')) {
            return collect();
        }

        return Plan::where('is_active', true)->orderBy('price_cents')->get();
    }
}; ?>

<div class="space-y-5">

    @if (! $this->ready)
        <x-admin.migration-pending what="Download settings" table="settings" />
    @else

        @if (session('ok'))
            <div class="rounded-2xl border border-success/25 bg-success/[0.06] px-5 py-3.5 text-[0.86rem] text-paper/75">
                <x-icon name="circle-check" style="solid" class="mr-1.5 text-[0.8rem] text-success" />
                {{ session('ok') }}
            </div>
        @endif

        {{-- ══════════════════════════════════════════════════════════════
             WHAT A VISITOR GETS RIGHT NOW
             ══════════════════════════════════════════════════════════════ --}}
        @php $state = $this->state; @endphp

        <div @class([
            'rounded-2xl border px-5 py-4',
            'border-success/25 bg-success/[0.06]' => $state['tone'] === 'open',
            'border-hairline bg-panel' => $state['tone'] === 'closed',
        ])>
            <div class="flex items-start gap-3.5">
                <span @class([
                    'grid size-9 shrink-0 place-items-center rounded-full',
                    'bg-success/15 text-success' => $state['tone'] === 'open',
                    'bg-paper/[0.07] text-paper/35' => $state['tone'] === 'closed',
                ])>
                    <x-icon :name="$state['icon']" style="solid" class="text-[0.85rem]" />
                </span>

                <div class="min-w-0">
                    <div class="text-[0.95rem] font-medium">{{ $state['headline'] }}</div>
                    <p class="mt-1 max-w-[80ch] text-[0.82rem] leading-relaxed text-paper/50">{{ $state['detail'] }}</p>
                </div>
            </div>
        </div>

        {{-- ══════════════════════════════════════════════════════════════
             THE THING THIS SCREEN IS NOT
             ══════════════════════════════════════════════════════════════
             Worth saying out loud, because "downloads without an account"
             is exactly the phrase that makes an operator reach for this
             screen when what they are actually worried about is a scraper.
             ══════════════════════════════════════════════════════════════ --}}
        <div class="rounded-2xl border border-info/25 bg-info/[0.06] px-5 py-4">
            <div class="flex items-start gap-3.5">
                <x-icon name="circle-info" style="solid" class="mt-[0.2rem] shrink-0 text-[0.85rem] text-info" />
                <div class="min-w-0">
                    <div class="text-[0.9rem] text-paper/80">This number is a nudge, not a wall.</div>
                    <p class="mt-1 max-w-[84ch] text-[0.82rem] leading-relaxed text-paper/50">
                        The free allowance is counted in a cookie, so anybody willing to open a private window can
                        reset it. <strong class="text-paper/70">That is fine, and it is the design.</strong> Its job
                        is turning a curious visitor into a registered one; somebody who works around it was never
                        going to register, and it cost one file.
                    </p>
                    <p class="mt-2 max-w-[84ch] text-[0.82rem] leading-relaxed text-paper/50">
                        Stopping a <em>script</em> taking the whole catalogue is a different problem with a different
                        defence, and it is already running: twenty downloads a minute and two hundred an hour per
                        address, plus the traffic watch and the block list in
                        <a href="{{ route('admin.security.abuse') }}" wire:navigate class="underline underline-offset-2 hover:text-paper">Security → Abuse</a>.
                        The tempting mistake is to make the allowance count by IP instead — and then an office, a
                        university or a whole mobile network behind one carrier address is a single "person", and a
                        hundred real visitors are blocked to fail to stop one.
                    </p>
                </div>
            </div>
        </div>

        <form wire:submit="save"
              x-data="{ dirty: false }"
              x-on:input="dirty = true"
              x-on:change="dirty = true"
              x-on:saved.window="dirty = false"
              class="space-y-5 pb-24">

            @foreach (\App\Support\Downloads::SECTIONS as $sectionKey => $section)
                <div class="rounded-2xl border border-hairline bg-panel" wire:key="sec-{{ $sectionKey }}">
                    <div class="border-b border-hairline px-5 py-4">
                        <h2 class="text-[0.95rem] font-medium">{{ $section['label'] }}</h2>
                        @if ($section['note'])
                            <p class="mt-0.5 max-w-[76ch] text-[0.78rem] leading-relaxed text-paper/40">{{ $section['note'] }}</p>
                        @endif
                    </div>

                    <div class="divide-y divide-hairline">
                        @foreach ($section['fields'] as $field)
                            @php
                                $meta = \App\Support\Downloads::FIELDS[$field];
                                $guestsOn = ($values['guest_enabled'] ?? '0') === '1';

                                // Only the switch has a reach worth spelling
                                // out — and it has one because its name
                                // sounds broader than it is. It does not
                                // touch premium sounds and it does not touch
                                // a member's daily allowance, which are the
                                // two things somebody would assume it does.
                                $reach = $field === 'guest_enabled'
                                    ? [
                                        ['Every download button on the site',
                                         'The page somebody lands on when a download is refused'],
                                        ['Premium sounds — always an account, whatever this says',
                                         'Daily limits for members — those belong to the plan'],
                                    ]
                                    : [[], []];
                            @endphp

                            <div wire:key="f-{{ $field }}">
                                <x-admin.setting-field
                                    :label="$meta['label']"
                                    :name="'values.'.$field"
                                    :affects="$meta['help'] ?: null"
                                    :used-by="$reach[0]"
                                    :not-used-by="$reach[1]">

                                    @switch($meta['type'])

                                        @case('bool')
                                            @php $isOn = ($values[$field] ?? '0') === '1'; @endphp

                                            <div class="inline-flex rounded-xl bg-raised p-1">
                                                <button type="button" x-on:click="dirty = true"
                                                        wire:click="setFlag('{{ $field }}', '1')"
                                                        @class([
                                                            'rounded-lg px-6 py-1.5 text-sm transition',
                                                            'bg-success text-ink' => $isOn,
                                                            'text-paper/45 hover:text-paper' => ! $isOn,
                                                        ])>On</button>

                                                <button type="button" x-on:click="dirty = true"
                                                        wire:click="setFlag('{{ $field }}', '0')"
                                                        @class([
                                                            'rounded-lg px-6 py-1.5 text-sm transition',
                                                            'bg-rail text-paper' => ! $isOn,
                                                            'text-paper/45 hover:text-paper' => $isOn,
                                                        ])>Off</button>
                                            </div>
                                            @break

                                        @case('number')
                                            <input type="number" min="1" max="25"
                                                   wire:model.live.debounce.500ms="values.{{ $field }}"
                                                   placeholder="{{ $this->fallback($field) }}"
                                                   @disabled(! $guestsOn)
                                                   class="w-28 rounded-lg border-0 bg-raised px-3 py-2.5 text-[0.84rem] focus:outline-none focus:ring-1 focus:ring-brand disabled:opacity-40" />

                                            @unless ($guestsOn)
                                                <p class="mt-2 text-[0.76rem] text-paper/30">
                                                    Nothing to set while downloads without an account are off.
                                                </p>
                                            @endunless
                                            @break

                                        @default
                                            <textarea wire:model.live.debounce.500ms="values.{{ $field }}" rows="2"
                                                      maxlength="300"
                                                      placeholder="{{ \App\Support\Downloads::DEFAULT_PROMPT }}"
                                                      @disabled(! $guestsOn)
                                                      class="w-full resize-y rounded-lg border-0 bg-raised px-3 py-2.5 text-[0.84rem] leading-relaxed focus:outline-none focus:ring-1 focus:ring-brand disabled:opacity-40"></textarea>
                                    @endswitch
                                </x-admin.setting-field>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach

            {{-- ══════════════════════════════════════════════════════════
                 THE THREE THINGS THAT ARE NOT SETTINGS
                 ══════════════════════════════════════════════════════════
                 Each one is a field somebody will come here looking for.
                 Saying where it actually lives is cheaper than the afternoon
                 they spend deciding the screen is incomplete. --}}
            <div class="rounded-2xl border border-hairline bg-panel">
                <div class="border-b border-hairline px-5 py-4">
                    <h2 class="text-[0.95rem] font-medium">Decided elsewhere, on purpose</h2>
                    <p class="mt-0.5 max-w-[76ch] text-[0.78rem] leading-relaxed text-paper/40">
                        Three things that sound like they belong on this screen. Two of them have a better home and
                        one of them has a single right answer.
                    </p>
                </div>

                <div class="divide-y divide-hairline">

                    {{-- 1 — daily limits --}}
                    <div class="px-5 py-5">
                        <div class="flex items-start gap-3.5">
                            <span class="grid size-9 shrink-0 place-items-center rounded-full bg-paper/[0.07] text-paper/40">
                                <x-icon name="layer-group" style="solid" class="text-[0.8rem]" />
                            </span>
                            <div class="min-w-0 flex-1">
                                <div class="text-[0.88rem] text-paper/80">How much a member gets each day</div>
                                <p class="mt-1 max-w-[74ch] text-[0.8rem] leading-relaxed text-paper/45">
                                    That number is what a plan <em>is</em>, so it lives on the plan. A copy here would
                                    disagree with the plans table the first time either one was edited, and the version
                                    anybody believed would be whichever screen they happened to open.
                                </p>

                                @if ($this->plans->isNotEmpty())
                                    <div class="mt-3 flex flex-wrap gap-2">
                                        @foreach ($this->plans as $plan)
                                            <span class="rounded-lg bg-rail px-3 py-1.5 text-[0.78rem] text-paper/55" wire:key="plan-{{ $plan->id }}">
                                                <span class="text-paper/80">{{ $plan->name }}</span>
                                                <span class="text-paper/30">·</span>
                                                {{ $plan->isUnlimited() ? 'unlimited' : $plan->daily_download_limit.' a day' }}
                                                @if ($plan->allows_premium)
                                                    <span class="text-paper/30">·</span> premium
                                                @endif
                                            </span>
                                        @endforeach
                                    </div>
                                @else
                                    <p class="mt-3 text-[0.78rem] text-paper/30">
                                        No plans yet. Billing → Plans is not built, so every signed-in member currently
                                        falls back to whatever the <span class="font-mono text-paper/45">free</span> plan
                                        row says — and if there is no such row, they cannot download at all.
                                    </p>
                                @endif
                            </div>
                        </div>
                    </div>

                    {{-- 2 — re-downloading --}}
                    <div class="px-5 py-5">
                        <div class="flex items-start gap-3.5">
                            <span class="grid size-9 shrink-0 place-items-center rounded-full bg-paper/[0.07] text-paper/40">
                                <x-icon name="rotate-left" style="solid" class="text-[0.8rem]" />
                            </span>
                            <div class="min-w-0">
                                <div class="text-[0.88rem] text-paper/80">
                                    Re-downloading is free for {{ \App\Support\Downloads::REGRAB_DAYS }} days
                                </div>
                                <p class="mt-1 max-w-[74ch] text-[0.8rem] leading-relaxed text-paper/45">
                                    Fetching again a sound you already took does not cost a slot and does not count as
                                    a download. It used to do both — which meant somebody re-fetching one file five
                                    times was charged five times <em>and</em> pushed that sound up the popularity
                                    ordering the home page uses. That was a bug with one right answer, so it is a
                                    constant rather than a field.
                                </p>
                            </div>
                        </div>
                    </div>

                    {{-- 3 — the filename --}}
                    <div class="px-5 py-5">
                        <div class="flex items-start gap-3.5">
                            <span class="grid size-9 shrink-0 place-items-center rounded-full bg-paper/[0.07] text-paper/40">
                                <x-icon name="file-audio" style="solid" class="text-[0.8rem]" />
                            </span>
                            <div class="min-w-0">
                                <div class="text-[0.88rem] text-paper/80">What the file is called</div>
                                <p class="mt-1 max-w-[74ch] text-[0.8rem] leading-relaxed text-paper/45">
                                    The site name, then the title:
                                    <code class="rounded bg-rail px-1.5 py-0.5 font-mono text-[0.76rem] text-paper/70">{{ \App\Support\Downloads::filename('Thunder clap') }}</code>.
                                    It is the only piece of marketing that survives into somebody else's editing
                                    timeline, and it makes "what was the file called?" answerable in support. A
                                    pattern field would earn nothing and would let a filename be made unusable.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <x-admin.save-bar />
        </form>
    @endif
</div>
