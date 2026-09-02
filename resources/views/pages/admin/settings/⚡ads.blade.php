<?php

use App\Models\ActivityLog;
use App\Models\Setting;
use App\Support\Ads;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.admin')] #[Title('Ads')] class extends Component {
    /** @var array<string, string> */
    public array $values = [];

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $stored = Setting::cached();

        foreach (Ads::FIELDS as $field => $meta) {
            $saved = (string) ($stored[$meta['key']] ?? '');

            $this->values[$field] = $meta['type'] === 'bool' && $saved === ''
                ? Ads::defaultValue($field)
                : $saved;
        }
    }

    public function save(): void
    {
        $this->validate([
            'values.sound_height' => ['nullable', 'integer', 'between:60,1200'],
            'values.blog_height' => ['nullable', 'integer', 'between:60,1200'],
            'values.catalog_height' => ['nullable', 'integer', 'between:60,1200'],
            'values.catalog_after' => ['nullable', 'integer', 'between:1,100'],
            'values.loader' => ['nullable', 'string', 'max:4000'],
            'values.sound_code' => ['nullable', 'string', 'max:4000'],
            'values.catalog_code' => ['nullable', 'string', 'max:4000'],
            'values.blog_code' => ['nullable', 'string', 'max:4000'],
            'values.ads_txt' => ['nullable', 'string', 'max:8000'],
        ]);

        $before = Setting::cached();
        $changed = 0;

        foreach (Ads::FIELDS as $field => $meta) {
            $new = trim((string) ($this->values[$field] ?? ''));
            $old = (string) ($before[$meta['key']] ?? '');

            if ($new === Ads::defaultValue($field)) {
                $new = '';
            }

            if ($new === $old) {
                continue;
            }

            Setting::put($meta['key'], $new === '' ? null : $new, 'ads');

            /*
             * The ad CODE is not written to the activity log, only the fact
             * that it changed. It is a few thousand characters of somebody
             * else's markup, and a log that swallows it becomes unreadable
             * for every other row in it. The switches and the numbers do get
             * their before-and-after, because those are the ones you would
             * want to undo.
             */
            $isCode = $meta['type'] === 'code';

            ActivityLog::record('settings.updated', null,
                'Ads — '.$meta['label'].($new === '' ? ' cleared' : ' changed'),
                $isCode
                    ? ['setting' => $meta['key']]
                    : ['setting' => $meta['key'], 'from' => $old ?: null, 'to' => $new ?: null]);

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
        if (array_key_exists($field, Ads::FIELDS) && in_array($value, ['0', '1'], true)) {
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
        $value = config('dbelo.'.Ads::FIELDS[$field]['key']);

        return is_bool($value) ? ($value ? 'on' : 'off') : (string) $value;
    }

    /** What is actually running on the site, in one sentence. */
    #[Computed]
    public function state(): array
    {
        if (($this->values['enabled'] ?? '0') !== '1') {
            return ['tone' => 'off', 'headline' => 'No advertising anywhere',
                'detail' => 'No block renders and no third-party script is loaded. This is the state to be in until a domain and a consent tool are settled.'];
        }

        if (($this->values['test_mode'] ?? '0') === '1') {
            return ['tone' => 'test', 'headline' => 'Test mode',
                'detail' => 'Every block draws a grey box at its real size. Nothing is requested from the network, so nothing is counted and nothing is earned — this is for judging the layout.'];
        }

        $live = collect(['sound' => 'the sound page', 'catalog' => 'the catalogue', 'blog' => 'blog posts'])
            ->filter(fn ($label, $slot) => ($this->values[$slot.'_on'] ?? '0') === '1'
                && trim((string) ($this->values[$slot.'_code'] ?? '')) !== '')
            ->values();

        if ($live->isEmpty()) {
            return ['tone' => 'off', 'headline' => 'Switched on, but no block is running',
                'detail' => 'Advertising is enabled and every block is either off or has no code pasted into it.'];
        }

        return ['tone' => 'live', 'headline' => 'Advertising is live',
            'detail' => 'Running on '.$live->join(', ', ' and ').'.'
                .(($this->values['hide_for_subscribers'] ?? '0') === '1' ? ' Hidden from subscribers.' : ' Subscribers see it too.')];
    }
}; ?>

<div class="space-y-5">

    @if (! $this->ready)
        <x-admin.migration-pending what="Ad settings" table="settings" />
    @else

        @if (session('ok'))
            <div class="rounded-2xl border border-success/25 bg-success/[0.06] px-5 py-3.5 text-[0.86rem] text-paper/75">
                <x-icon name="circle-check" style="solid" class="mr-1.5 text-[0.8rem] text-success" />
                {{ session('ok') }}
            </div>
        @endif

        {{-- ══════════════════════════════════════════════════════════════
             WHAT IS RUNNING RIGHT NOW
             ══════════════════════════════════════════════════════════════ --}}
        @php $state = $this->state; @endphp

        <div @class([
            'rounded-2xl border px-5 py-4',
            'border-success/25 bg-success/[0.06]' => $state['tone'] === 'live',
            'border-info/25 bg-info/[0.06]' => $state['tone'] === 'test',
            'border-hairline bg-panel' => $state['tone'] === 'off',
        ])>
            <div class="flex items-start gap-3.5">
                <span @class([
                    'grid size-9 shrink-0 place-items-center rounded-full',
                    'bg-success/15 text-success' => $state['tone'] === 'live',
                    'bg-info/15 text-info' => $state['tone'] === 'test',
                    'bg-paper/[0.07] text-paper/35' => $state['tone'] === 'off',
                ])>
                    <x-icon :name="$state['tone'] === 'live' ? 'rectangle-ad' : ($state['tone'] === 'test' ? 'flask' : 'ban')"
                            style="solid" class="text-[0.85rem]" />
                </span>

                <div class="min-w-0">
                    <div class="text-[0.95rem] font-medium">{{ $state['headline'] }}</div>
                    <p class="mt-1 max-w-[78ch] text-[0.82rem] leading-relaxed text-paper/50">{{ $state['detail'] }}</p>
                </div>
            </div>
        </div>

        {{-- ══════════════════════════════════════════════════════════════
             THE THING THIS SCREEN CANNOT PROTECT YOU FROM
             ══════════════════════════════════════════════════════════════ --}}
        <div class="rounded-2xl border border-warning/25 bg-warning/[0.06] px-5 py-4">
            <div class="flex items-start gap-3.5">
                <x-icon name="triangle-exclamation" style="solid" class="mt-[0.2rem] shrink-0 text-[0.85rem] text-warning" />
                <div class="min-w-0">
                    <div class="text-[0.9rem] text-paper/80">These boxes run somebody else's JavaScript on your site.</div>
                    <p class="mt-1 max-w-[82ch] text-[0.82rem] leading-relaxed text-paper/50">
                        Every other free-text field in this panel refuses HTML, because a headline that accepts tags
                        accepts a script. This one cannot: an ad tag <em>is</em> a script from a third party, and a
                        sanitised version of it would simply not work. So it is admin-only, it never loads in the panel
                        or on the legal, pricing and sign-in pages, and it is off entirely until you switch it on.
                        Beyond that the network can do whatever its code does — paste only what the network gave you.
                    </p>
                    <p class="mt-2 max-w-[82ch] text-[0.82rem] leading-relaxed text-paper/50">
                        <strong class="text-paper/70">Before going live in Europe:</strong> Google requires a certified
                        consent tool for personalised ads to EEA and UK visitors. That is not something this screen can
                        provide, and it is worth settling alongside the country of operation.
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

            @foreach (\App\Support\Ads::SECTIONS as $sectionKey => $section)
                <div class="rounded-2xl border border-hairline bg-panel" wire:key="sec-{{ $sectionKey }}">
                    <div class="border-b border-hairline px-5 py-4">
                        <h2 class="text-[0.95rem] font-medium">{{ $section['label'] }}</h2>
                        @if ($section['note'])
                            <p class="mt-0.5 max-w-[76ch] text-[0.78rem] leading-relaxed text-paper/40">{{ $section['note'] }}</p>
                        @endif
                    </div>

                    <div class="divide-y divide-hairline">
                        @foreach ($section['fields'] as $field)
                            @php $meta = \App\Support\Ads::FIELDS[$field]; @endphp

                            <div wire:key="f-{{ $field }}">
                                <x-admin.setting-field
                                    :label="$meta['label']"
                                    :name="'values.'.$field"
                                    :affects="$meta['help'] ?: null">

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
                                            <input type="number" min="1" max="1200" wire:model.live.debounce.500ms="values.{{ $field }}"
                                                   placeholder="{{ $this->fallback($field) }}"
                                                   class="w-32 rounded-lg border-0 bg-raised px-3 py-2.5 text-[0.84rem] focus:outline-none focus:ring-1 focus:ring-brand" />
                                            @break

                                        @default
                                            {{-- spellcheck off and a mono face:
                                                 this is code, and a squiggly
                                                 red underline under a publisher
                                                 id reads as an error. --}}
                                            <textarea wire:model="values.{{ $field }}" rows="5"
                                                      spellcheck="false" autocomplete="off"
                                                      placeholder="Paste the code the network gave you"
                                                      class="w-full resize-y rounded-lg border-0 bg-rail px-3 py-2.5 font-mono text-[0.76rem] leading-relaxed focus:outline-none focus:ring-1 focus:ring-brand"></textarea>
                                    @endswitch
                                </x-admin.setting-field>
                            </div>
                        @endforeach

                        @if ($sectionKey === 'txt')
                            <div class="px-5 py-4">
                                <p class="text-[0.8rem] leading-relaxed text-paper/45">
                                    Served at
                                    <a href="{{ url('/ads.txt') }}" target="_blank" class="font-mono text-paper/70 underline underline-offset-2">{{ url('/ads.txt') }}</a>.
                                    Empty here means the file returns a 404, which is the same as not having one.
                                </p>
                            </div>
                        @endif
                    </div>
                </div>
            @endforeach

            {{-- ══════════════════════════════════════════════════════════
                 WHERE ADS NEVER APPEAR
                 ══════════════════════════════════════════════════════════ --}}
            <div class="rounded-2xl border border-hairline bg-panel px-5 py-4">
                <div class="text-[0.88rem] text-paper/75">Pages that never carry advertising</div>
                <p class="mt-1.5 max-w-[82ch] text-[0.8rem] leading-relaxed text-paper/45">
                    The admin panel, the legal pages, the copyright form, pricing, sign-in, registration and password
                    recovery — plus the upload and library screens. Not a setting: an ad beside your privacy policy
                    undermines the document it sits next to, and one beside a form is both a policy problem for the
                    network and a distraction on the three screens where a distraction costs more than the ad pays.
                    The list is <span class="font-mono text-paper/60">App\Support\Ads::NEVER</span>.
                </p>
            </div>

            <x-admin.save-bar />
        </form>
    @endif
</div>
