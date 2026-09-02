<?php

use App\Models\ActivityLog;
use App\Models\Setting;
use App\Support\CustomCode;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.admin')] #[Title('Code & tracking')] class extends Component {
    /** @var array<string, string> */
    public array $values = [];

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $stored = Setting::cached();

        foreach (CustomCode::FIELDS as $field => $meta) {
            $this->values[$field] = (string) ($stored[$meta['key']] ?? '');
        }
    }

    public function save(): void
    {
        $this->validate([
            'values.google_verification' => ['nullable', 'string', 'max:300'],
            'values.bing_verification' => ['nullable', 'string', 'max:300'],
            'values.head' => ['nullable', 'string', 'max:8000'],
            'values.body_start' => ['nullable', 'string', 'max:8000'],
            'values.body_end' => ['nullable', 'string', 'max:8000'],
        ]);

        $before = Setting::cached();
        $changed = 0;

        foreach (CustomCode::FIELDS as $field => $meta) {
            $new = trim((string) ($this->values[$field] ?? ''));
            $old = (string) ($before[$meta['key']] ?? '');

            if ($new === $old) {
                continue;
            }

            Setting::put($meta['key'], $new === '' ? null : $new, 'code');

            /*
             * The code itself is not written to the activity log — only that
             * it changed. Eight thousand characters of somebody else's
             * markup in an audit row makes every other row in the table
             * unreadable, and the useful fact is "this was edited, by whom,
             * when", which is what you go looking for when the site starts
             * behaving oddly.
             */
            ActivityLog::record('settings.updated', null,
                'Code — '.$meta['label'].($new === '' ? ' cleared' : ' changed'),
                ['setting' => $meta['key']]);

            $changed++;
        }

        $this->dispatch('saved');

        session()->flash('ok', match ($changed) {
            0 => 'Nothing had changed.',
            1 => 'Saved.',
            default => "Saved — {$changed} changes.",
        });
    }

    /* ═════════════════════════════ Helpers ═════════════════════════════ */

    #[Computed]
    public function ready(): bool
    {
        return Schema::hasTable('settings');
    }

    /**
     * What a verification field will actually publish.
     *
     * The whole tag pasted in becomes the code alone; a code that is not a
     * code becomes nothing. Showing the result under the box is the
     * difference between "it saved" and "it will work".
     */
    public function tokenPreview(string $field): array
    {
        $raw = trim((string) ($this->values[$field] ?? ''));

        if ($raw === '') {
            return ['state' => 'empty', 'text' => 'Not set — the tag is not printed.'];
        }

        $token = CustomCode::token($field);

        if ($token === '') {
            return ['state' => 'bad', 'text' => 'That does not look like a verification code, so nothing will be printed. Paste the code, or the whole meta tag Google gave you.'];
        }

        return ['state' => 'ok', 'text' => $token !== $raw
            ? 'Read out of the tag: '.$token
            : 'Will publish: '.$token];
    }
}; ?>

<div class="space-y-5">

    @if (! $this->ready)
        <x-admin.migration-pending what="Code settings" table="settings" />
    @else

        @if (session('ok'))
            <div class="rounded-2xl border border-success/25 bg-success/[0.06] px-5 py-3.5 text-[0.86rem] text-paper/75">
                <x-icon name="circle-check" style="solid" class="mr-1.5 text-[0.8rem] text-success" />
                {{ session('ok') }}
            </div>
        @endif

        {{-- ══════════════════════════════════════════════════════════════
             THE WARNING THAT CANNOT BE ENGINEERED AWAY
             ══════════════════════════════════════════════════════════════ --}}
        <div class="rounded-2xl border border-warning/25 bg-warning/[0.06] px-5 py-4">
            <div class="flex items-start gap-3.5">
                <x-icon name="triangle-exclamation" style="solid" class="mt-[0.2rem] shrink-0 text-[0.85rem] text-warning" />
                <div class="min-w-0">
                    <div class="text-[0.9rem] text-paper/80">Whatever you paste here runs on the public site.</div>
                    <p class="mt-1 max-w-[84ch] text-[0.82rem] leading-relaxed text-paper/50">
                        There is no safe version of this box — a script tag that has been sanitised is a script tag
                        that does not work. What the panel can do instead is limit the damage: nothing here loads in
                        the admin, the head code is printed <em>after</em> the site's own stylesheet so a broken tag
                        cannot stop the design from loading, and only an admin can reach this screen. Paste what the
                        service gave you and nothing else, then open the site and check it still looks right.
                    </p>
                    <p class="mt-2 max-w-[84ch] text-[0.82rem] leading-relaxed text-paper/50">
                        <strong class="text-paper/70">Ad code does not go here.</strong> It has its own screen, with
                        its own switch and its own rule about subscribers —
                        <a href="{{ route('admin.settings.ads') }}" wire:navigate class="underline underline-offset-2 hover:text-paper">Settings → Ads</a>.
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

            @foreach (\App\Support\CustomCode::SECTIONS as $sectionKey => $section)
                <div class="rounded-2xl border border-hairline bg-panel" wire:key="sec-{{ $sectionKey }}">
                    <div class="border-b border-hairline px-5 py-4">
                        <h2 class="text-[0.95rem] font-medium">{{ $section['label'] }}</h2>
                        @if ($section['note'])
                            <p class="mt-0.5 max-w-[76ch] text-[0.78rem] leading-relaxed text-paper/40">{{ $section['note'] }}</p>
                        @endif
                    </div>

                    <div class="divide-y divide-hairline">
                        @foreach ($section['fields'] as $field)
                            @php $meta = \App\Support\CustomCode::FIELDS[$field]; @endphp

                            <div wire:key="f-{{ $field }}">
                                <x-admin.setting-field
                                    :label="$meta['label']"
                                    :name="'values.'.$field"
                                    :affects="$meta['help'] ?: null">

                                    @if ($meta['type'] === 'text')
                                        <input type="text" wire:model.live.debounce.400ms="values.{{ $field }}"
                                               maxlength="300" spellcheck="false" autocomplete="off"
                                               placeholder="Paste the code, or the whole meta tag"
                                               class="w-full rounded-lg border-0 bg-raised px-3 py-2.5 font-mono text-[0.8rem] focus:outline-none focus:ring-1 focus:ring-brand" />

                                        @php $preview = $this->tokenPreview($field); @endphp
                                        <p @class([
                                            'mt-2 break-all text-[0.78rem] leading-relaxed',
                                            'text-success' => $preview['state'] === 'ok',
                                            'text-danger' => $preview['state'] === 'bad',
                                            'text-paper/30' => $preview['state'] === 'empty',
                                        ])>{{ $preview['text'] }}</p>

                                    @else
                                        <textarea wire:model="values.{{ $field }}" rows="6"
                                                  spellcheck="false" autocomplete="off"
                                                  placeholder="Paste the code the service gave you"
                                                  class="w-full resize-y rounded-lg border-0 bg-rail px-3 py-2.5 font-mono text-[0.76rem] leading-relaxed focus:outline-none focus:ring-1 focus:ring-brand"></textarea>
                                    @endif
                                </x-admin.setting-field>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach

            {{-- ══════════════════════════════════════════════════════════
                 WHAT YOU ALREADY HAVE
                 ══════════════════════════════════════════════════════════ --}}
            <div class="rounded-2xl border border-hairline bg-panel px-5 py-4">
                <p class="max-w-[84ch] text-[0.8rem] leading-relaxed text-paper/45">
                    <x-icon name="circle-info" style="solid" class="mr-1 text-[0.72rem] text-info" />
                    <strong class="text-paper/65">You already have your own analytics.</strong>
                    Visits, downloads, searches and the daily rollup are recorded on this server and shown in
                    <a href="{{ route('admin.analytics') }}" wire:navigate class="underline underline-offset-2 hover:text-paper">Tools → Analytics</a>.
                    Google Analytics is a choice on top of that, not a requirement — and, like the captcha and the
                    advertising, it sends visitor data to a third party and brings a consent obligation with it in
                    Europe. Your own numbers do not.
                </p>
            </div>

            <x-admin.save-bar />
        </form>
    @endif
</div>
