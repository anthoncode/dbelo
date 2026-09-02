<?php

use App\Models\ActivityLog;
use App\Models\Setting;
use App\Support\Copy;
use App\Support\Homepage;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.admin')] #[Title('Homepage')] class extends Component {
    /**
     * Every field, keyed by its short name.
     *
     * A flat array with no dots in the keys: Livewire reads a dot in
     * wire:model as nesting, so "values.home.hero.title" would try to walk
     * into an array that does not exist. App\Support\Homepage holds the map
     * from these short names to the real dotted setting keys.
     *
     * @var array<string, string>
     */
    public array $values = [];

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $stored = Setting::cached();

        foreach (Homepage::FIELDS as $field => $meta) {
            $saved = (string) ($stored[$meta['key']] ?? '');

            /*
             * Text boxes start EMPTY when nothing was saved, so the
             * placeholder can show the default and "empty means the default"
             * stays true.
             *
             * A SWITCH CANNOT DO THAT. It has two positions and it is always
             * in one of them, so leaving it "unset" just means it shows a
             * state that may not be the state the site is in. It starts on
             * the effective value instead — and save() then declines to
             * store a choice that matches the default, so the table still
             * holds only real deviations.
             */
            $this->values[$field] = $meta['type'] === 'bool' && $saved === ''
                ? Homepage::defaultValue($field)
                : $saved;
        }
    }

    public function save(): void
    {
        $this->validate($this->rules(), [
            'values.bar_link.regex' => 'Use a path starting with / or a full http:// or https:// address.',
        ]);

        $before = Setting::cached();
        $changed = 0;

        foreach (Homepage::FIELDS as $field => $meta) {
            $new = trim((string) ($this->values[$field] ?? ''));
            $old = (string) ($before[$meta['key']] ?? '');

            // Choosing exactly what the default already says is not a change
            // worth a row. Without this the eight switches would each write
            // a setting, and an audit entry about it, the first time anybody
            // pressed Save on this screen.
            if ($new === Homepage::defaultValue($field)) {
                $new = '';
            }

            if ($new === $old) {
                continue;
            }

            Setting::put($meta['key'], $new === '' ? null : $new, 'homepage');

            ActivityLog::record(
                'settings.updated',
                null,
                'Homepage — '.$meta['label'].($new === '' ? ' reset to the default' : ' changed'),
                ['setting' => $meta['key'], 'from' => $old ?: null, 'to' => $new ?: null],
            );

            $changed++;
        }

        // Clears the floating bar's "Unsaved changes" light. Dispatched
        // here rather than on submit, so a form that failed validation keeps
        // saying it has work outstanding — because it does.
        $this->dispatch('saved');

        session()->flash('ok', match ($changed) {
            0 => 'Nothing had changed.',
            1 => 'Saved.',
            default => "Saved — {$changed} changes.",
        });
    }

    /**
     * Reset one field to what config/dbelo.php says.
     *
     * Per field rather than a single "reset everything": undoing one bad
     * headline should not also throw away the four things you got right.
     */
    public function revert(string $field): void
    {
        if (! array_key_exists($field, Homepage::FIELDS)) {
            return;
        }

        $this->values[$field] = '';

        $this->save();
    }

    /**
     * Flip a switch.
     *
     * A named method rather than $set, and buttons rather than radios. The
     * radios had no `name` attribute, so the browser never treated them as
     * one group — clicking Off could not uncheck On, because as far as the
     * page was concerned they were two unrelated controls that happened to
     * sit next to each other.
     */
    public function setFlag(string $field, string $value): void
    {
        if (array_key_exists($field, Homepage::FIELDS) && in_array($value, ['0', '1'], true)) {
            $this->values[$field] = $value;
        }
    }

    /** @return array<string, array<int, string>> */
    private function rules(): array
    {
        $rules = [];

        foreach (Homepage::FIELDS as $field => $meta) {
            // Nothing is required. Empty means "use the default", which is a
            // valid answer for every field on this screen.
            $rules["values.{$field}"] = match ($meta['type']) {
                'bool' => ['nullable', 'in:0,1'],
                'number' => ['nullable', 'integer', 'between:1,24'],
                'rich' => ['nullable', 'string', 'max:200'],
                'date' => ['nullable', 'date'],
                'select' => ['nullable', Rule::in(array_keys($meta['options']))],
                // A path or a full address, nothing else. Rejecting
                // javascript: and data: here is the point: this value ends up
                // in an href on every page of the site, and "the admin typed
                // it" stops being reassuring the moment there is a second
                // admin.
                'url' => ['nullable', 'string', 'max:300', 'regex:/^(https?:\/\/|\/)/i'],
                default => ['nullable', 'string', 'max:400'],
            };
        }

        return $rules;
    }

    /* ═════════════════════════════ Helpers ═════════════════════════════ */

    #[Computed]
    public function ready(): bool
    {
        return Schema::hasTable('settings');
    }

    /** What the field falls back to when it is left empty. */
    public function fallback(string $field): string
    {
        $value = config('dbelo.'.Homepage::FIELDS[$field]['key']);

        if (is_bool($value)) {
            return $value ? 'shown' : 'hidden';
        }

        return Str::limit(str_replace("\n", ' / ', (string) $value), 70);
    }

    /**
     * The effective value: what the page is actually rendering right now.
     *
     * The whole reason this method exists. A settings screen that only shows
     * what you typed cannot tell you whether the site agrees, and the last
     * two rounds of this project were spent discovering that it did not.
     */
    public function effective(string $field): string
    {
        return Homepage::text($field);
    }

    /**
     * The bar as it would look, built from the boxes rather than the table.
     *
     * Deliberately ignores scope, audience, the end date and the dismissal
     * cookie: those decide WHETHER it appears, and the sentence above the
     * preview already answers that. This one answers the other question —
     * what does it look like — and answering both in the same widget would
     * mean an empty preview whenever a condition happened to exclude you.
     *
     * @return array<string, mixed>
     */
    public function barPreview(): array
    {
        $value = function (string $field): string {
            $typed = trim((string) ($this->values[$field] ?? ''));

            return $typed !== '' ? $typed : Homepage::defaultValue($field);
        };

        $tone = $value('bar_tone');
        $link = $value('bar_link');

        if (! array_key_exists($tone, Homepage::FIELDS['bar_tone']['options'])) {
            $tone = 'brand';
        }

        return [
            'message' => $value('bar_message') ?: 'Your message goes here.',
            'button' => $value('bar_button'),
            'link' => $link,
            'external' => $link !== '' && str_starts_with($link, 'http'),
            'dismissible' => ($this->values['bar_dismissible'] ?? '1') === '1',
            'hash' => '',
            'tone' => $tone,
            'icon' => Homepage::iconFor($tone),
        ];
    }

    public function preview(string $field): \Illuminate\Support\HtmlString
    {
        $typed = trim((string) ($this->values[$field] ?? ''));

        return Copy::rich($typed !== '' ? $typed : (string) config('dbelo.'.Homepage::FIELDS[$field]['key']));
    }
}; ?>

<div class="space-y-5">

    @if (! $this->ready)
        <x-admin.migration-pending what="Homepage settings" table="settings" />
    @else

        @if (session('ok'))
            <div class="rounded-2xl border border-success/25 bg-success/[0.06] px-5 py-3.5 text-[0.86rem] text-paper/75">
                <x-icon name="circle-check" style="solid" class="mr-1.5 text-[0.8rem] text-success" />
                {{ session('ok') }}
            </div>
        @endif

        {{-- ══════════════════════════════════════════════════════════════
             HOW THE HIGHLIGHT WORKS

             Stated once, at the top, rather than repeated under six
             textareas. Without it the asterisks in the boxes look like a
             mistake somebody left behind.
             ══════════════════════════════════════════════════════════════ --}}
        <div class="rounded-2xl border border-hairline bg-panel px-5 py-4">
            <p class="max-w-[80ch] text-[0.82rem] leading-relaxed text-paper/45">
                <x-icon name="wand-magic-sparkles" style="solid" class="mr-1.5 text-[0.75rem] text-brand" />
                Put <span class="font-mono text-paper/70">*asterisks*</span> around a word to give it the brand
                colour — <span class="font-mono text-paper/70">your *story* needs</span> renders as
                <span class="text-brand">story</span> in purple. A line break in the box is a line break on the page.
                HTML is not accepted and is shown as plain text on purpose: a headline box that accepts tags is a
                headline box that accepts a script, and the front page is the worst possible place for one.
            </p>
        </div>

        {{-- dirty is read by x-admin.save-bar, which is a sibling of the
             fields rather than their ancestor — so the flag has to live up
             here on the form, where their events actually bubble to. --}}
        <form wire:submit="save"
              x-data="{ dirty: false }"
              x-on:input="dirty = true"
              x-on:change="dirty = true"
              x-on:saved.window="dirty = false"
              class="space-y-5 pb-24">

            @foreach (\App\Support\Homepage::SECTIONS as $sectionKey => $section)
                <div class="rounded-2xl border border-hairline bg-panel" wire:key="sec-{{ $sectionKey }}">
                    <div class="border-b border-hairline px-5 py-4">
                        <h2 class="text-[0.95rem] font-medium">{{ $section['label'] }}</h2>
                        @if ($section['note'])
                            <p class="mt-0.5 max-w-[76ch] text-[0.78rem] leading-relaxed text-paper/40">{{ $section['note'] }}</p>
                        @endif
                    </div>

                    @if ($sectionKey === 'bar')
                        {{-- The bar itself, drawn by the same component the
                             site uses. Seeing the thing is a better answer to
                             "how do I switch this on" than any label. --}}
                        <div class="border-b border-hairline px-5 py-5">
                            <div class="micro mb-2 text-paper/25">Preview</div>
                            <div class="overflow-hidden rounded-xl">
                                <x-promo-bar :bar="$this->barPreview()" preview />
                            </div>
                        </div>

                        {{-- What the bar is actually doing, right now.
                             Nine fields decide whether this thing appears,
                             and without one sentence naming the condition
                             that caught you, "I filled it in and nothing
                             happened" is a guessing game. --}}
                        <div class="border-b border-hairline px-5 py-4">
                            {{-- dormant(), not explain().

                                 explain() answers "why am I, on this page,
                                 not seeing it" — and this page is
                                 /admin/settings/homepage, which is never the
                                 landing page. Asking it here would have
                                 reported "not showing" for ever while every
                                 visitor saw the bar perfectly. --}}
                            @php
                                $why = \App\Support\Homepage::dormant();
                            @endphp

                            <div @class([
                                'flex items-start gap-3 rounded-xl px-4 py-3',
                                'bg-success/[0.08]' => $why === null,
                                'bg-raised' => $why !== null,
                            ])>
                                <x-icon :name="$why === null ? 'circle-check' : 'eye-slash'" style="solid"
                                        class="mt-[0.15rem] shrink-0 text-[0.8rem] {{ $why === null ? 'text-success' : 'text-paper/30' }}" />

                                <div class="min-w-0">
                                    <div class="text-[0.86rem] text-paper/80">
                                        {{ $why === null ? 'The bar is live.' : 'The bar is not showing.' }}
                                    </div>

                                    <p class="mt-1 max-w-[70ch] text-[0.8rem] leading-relaxed text-paper/45">
                                        {{ $why ?? \App\Support\Homepage::reach() }}
                                    </p>

                                    @if ($why === null)
                                        <p class="mt-1 text-[0.78rem] text-paper/30">
                                            You may not see it yourself — the admin panel is not the site, and you
                                            may have closed it.
                                        </p>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endif

                    <div class="divide-y divide-hairline">
                        @foreach ($section['fields'] as $field)
                            @php $meta = \App\Support\Homepage::FIELDS[$field]; @endphp

                            {{-- The key goes on a wrapper, not on the
                                 component: a Blade component drops unknown
                                 attributes, so wire:key on it would never
                                 reach the DOM Livewire diffs. --}}
                            <div wire:key="f-{{ $field }}">
                            <x-admin.setting-field
                                :label="$meta['label']"
                                :name="'values.'.$field"
                                :affects="$meta['help'] ?: null">

                                @switch($meta['type'])

                                    @case('bool')
                                        @php $isOn = ($values[$field] ?? '1') === '1'; @endphp

                                        {{-- The active state is rendered by
                                             the SERVER, not by peer-checked:.

                                             peer-checked: is a class like any
                                             other, which means it exists only
                                             if it was in the source at build
                                             time — and against a stylesheet
                                             older than this file it silently
                                             does nothing. The switch then
                                             looks broken while working
                                             perfectly, with no error anywhere.
                                             State that matters should not
                                             depend on a class having been
                                             compiled. --}}
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
                                        <input type="number" min="1" max="24" wire:model.live.debounce.500ms="values.{{ $field }}"
                                               placeholder="{{ $this->fallback($field) }}"
                                               class="w-28 rounded-lg border-0 bg-raised px-3 py-2.5 text-[0.84rem] focus:outline-none focus:ring-1 focus:ring-brand" />
                                        @break

                                    @case('rich')
                                        <textarea wire:model.live.debounce.400ms="values.{{ $field }}" rows="2" maxlength="200"
                                                  placeholder="{{ $this->fallback($field) }}"
                                                  class="w-full resize-none rounded-lg border-0 bg-raised px-3 py-2.5 text-[0.84rem] leading-relaxed focus:outline-none focus:ring-1 focus:ring-brand"></textarea>

                                        {{-- Safe to print unescaped: Copy::rich()
                                             escapes the text before adding the
                                             only markup it allows. Showing it
                                             here is also the proof that the
                                             asterisks did what you meant. --}}
                                        <div class="mt-2 rounded-lg bg-rail px-4 py-3">
                                            <div class="text-[0.66rem] uppercase tracking-[0.14em] text-paper/25">On the page</div>
                                            <div class="mt-1 text-[0.95rem] leading-snug text-paper/80">{!! $this->preview($field) !!}</div>
                                        </div>
                                        @break

                                    @case('select')
                                        <select wire:model.live="values.{{ $field }}"
                                                class="w-full rounded-lg border-0 bg-raised px-3 py-2.5 text-[0.84rem] focus:outline-none focus:ring-1 focus:ring-brand">
                                            {{-- Without this the browser would
                                                 silently select the first
                                                 option for a field nobody has
                                                 touched, and the next save
                                                 would write it to the table as
                                                 a decision. --}}
                                            <option value="">Default — {{ $meta['options'][$this->fallback($field)] ?? $this->fallback($field) }}</option>

                                            @foreach ($meta['options'] as $value => $caption)
                                                <option value="{{ $value }}" wire:key="{{ $field }}-{{ $value }}">{{ $caption }}</option>
                                            @endforeach
                                        </select>
                                        @break

                                    @case('date')
                                        <input type="date" wire:model.live="values.{{ $field }}"
                                               class="rounded-lg border-0 bg-raised px-3 py-2.5 text-[0.84rem] focus:outline-none focus:ring-1 focus:ring-brand" />
                                        @break

                                    @case('url')
                                        <input type="text" wire:model.live.debounce.500ms="values.{{ $field }}" maxlength="300"
                                               inputmode="url" autocomplete="off" spellcheck="false"
                                               placeholder="/packs"
                                               class="w-full rounded-lg border-0 bg-raised px-3 py-2.5 font-mono text-[0.8rem] focus:outline-none focus:ring-1 focus:ring-brand" />
                                        @break

                                    @default
                                        <input type="text" wire:model.live.debounce.500ms="values.{{ $field }}" maxlength="400"
                                               placeholder="{{ $this->fallback($field) }}"
                                               class="w-full rounded-lg border-0 bg-raised px-3 py-2.5 text-[0.84rem] focus:outline-none focus:ring-1 focus:ring-brand" />
                                @endswitch

                                @if (filled($values[$field] ?? ''))
                                    <button type="button" wire:click="revert('{{ $field }}')"
                                            class="mt-2 text-[0.74rem] text-paper/30 underline-offset-2 transition hover:text-paper/60 hover:underline">
                                        Back to the default
                                    </button>
                                @endif
                            </x-admin.setting-field>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach

            <p class="px-1 text-[0.78rem] text-paper/30">
                Every change is written to the activity log with what it was before.
            </p>

            <x-admin.save-bar>
                <a href="{{ route('home') }}" target="_blank"
                   class="hidden shrink-0 rounded-xl bg-raised px-4 py-2.5 text-[0.82rem] text-paper/60 transition hover:text-paper sm:block">
                    Open the site
                </a>
            </x-admin.save-bar>
        </form>

        {{-- ══════════════════════════════════════════════════════════════
             WHAT IS NOT HERE
             ══════════════════════════════════════════════════════════════ --}}
        <div class="rounded-2xl border border-hairline bg-panel">
            <div class="border-b border-hairline px-5 py-4">
                <h2 class="text-[0.95rem] font-medium">Three things this screen deliberately does not do</h2>
            </div>

            <div class="divide-y divide-hairline text-[0.82rem] leading-relaxed">
                <div class="px-5 py-4">
                    <div class="text-paper/70">Choose which packs appear</div>
                    <p class="mt-1.5 max-w-[80ch] text-paper/45">
                        That is the <span class="font-mono text-paper/60">featured</span> flag, and it is set on the
                        pack itself — where you are already looking at the pack you are deciding about. A second
                        list here would be a copy of that decision, kept somewhere else, going stale.
                    </p>
                </div>

                <div class="px-5 py-4">
                    <div class="text-paper/70">Reorder the sections</div>
                    <p class="mt-1.5 max-w-[80ch] text-paper/45">
                        The headings are written as a conversation — "by what the sound <em>is</em>" is answered by
                        "or by what you are <em>making</em>" — and the tall "All in one" card only makes sense
                        directly after the packs. Reordering would break the page without anything failing, which is
                        the kind of control that costs more than it gives.
                    </p>
                </div>

                <div class="px-5 py-4">
                    <div class="text-paper/70">Colours, type and spacing</div>
                    <p class="mt-1.5 max-w-[80ch] text-paper/45">
                        Those belong to Appearance. This screen is about what the page says and what it shows,
                        not what it looks like.
                    </p>
                </div>
            </div>
        </div>
    @endif
</div>
