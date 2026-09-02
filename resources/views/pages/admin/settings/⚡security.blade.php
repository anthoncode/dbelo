<?php

use App\Models\ActivityLog;
use App\Models\Setting;
use App\Services\Captcha;
use App\Support\Security;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.admin')] #[Title('Security')] class extends Component {
    /** @var array<string, string> */
    public array $values = [];

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $stored = Setting::cached();

        foreach (Security::FIELDS as $field => $meta) {
            /*
             * A SECRET IS NEVER LOADED. Not even to be re-saved unchanged.
             *
             * A Livewire public property is serialised into the page: the
             * snapshot is in the HTML, readable in view-source, present in
             * any screenshot of this screen and in the browser's memory for
             * as long as the tab is open. A secret that goes into $values
             * has left the server, and no amount of type="password" on the
             * input takes it back.
             *
             * So the box always starts empty and means "the new one". Empty
             * on save means "leave what is stored alone".
             */
            if ($meta['type'] === 'secret') {
                $this->values[$field] = '';

                continue;
            }

            $saved = (string) ($stored[$meta['key']] ?? '');

            $this->values[$field] = $meta['type'] === 'bool' && $saved === ''
                ? Security::defaultValue($field)
                : $saved;
        }
    }

    public function save(): void
    {
        $this->validate($this->rules());

        $before = Setting::cached();
        $changed = 0;

        foreach (Security::FIELDS as $field => $meta) {
            $new = trim((string) ($this->values[$field] ?? ''));

            if ($meta['type'] === 'secret') {
                if ($new === '') {
                    continue;   // nothing typed: keep what is stored
                }

                Setting::put($meta['key'], $new, 'security', encrypted: true);

                // The VALUE is never logged — ActivityLog::scrub would catch
                // a key called "secret" anyway, but not relying on that is
                // cheaper than finding out it missed one.
                ActivityLog::record('settings.updated', null,
                    'Security — '.$meta['label'].' replaced',
                    ['setting' => $meta['key']]);

                $this->values[$field] = '';
                $changed++;

                continue;
            }

            $old = (string) ($before[$meta['key']] ?? '');

            if ($new === Security::defaultValue($field)) {
                $new = '';
            }

            if ($new === $old) {
                continue;
            }

            Setting::put($meta['key'], $new === '' ? null : $new, 'security');

            ActivityLog::record('settings.updated', null,
                'Security — '.$meta['label'].($new === '' ? ' reset to the default' : ' changed'),
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
        if (array_key_exists($field, Security::FIELDS) && in_array($value, ['0', '1'], true)) {
            $this->values[$field] = $value;
        }
    }

    /** Delete a stored secret outright. */
    public function forgetSecret(string $field): void
    {
        if ((Security::FIELDS[$field]['type'] ?? '') !== 'secret') {
            return;
        }

        Setting::put(Security::FIELDS[$field]['key'], null, 'security');

        ActivityLog::record('settings.updated', null,
            'Security — '.Security::FIELDS[$field]['label'].' removed',
            ['setting' => Security::FIELDS[$field]['key']]);

        session()->flash('ok', Security::FIELDS[$field]['label'].' removed.');
    }

    /** @return array<string, array<int, mixed>> */
    private function rules(): array
    {
        $rules = [];

        foreach (Security::FIELDS as $field => $meta) {
            $rules["values.{$field}"] = match ($meta['type']) {
                'bool' => ['nullable', 'in:0,1'],
                'number' => ['nullable', 'integer', 'between:0,20'],
                'select' => ['nullable', Rule::in(array_keys($meta['options']))],
                'secret' => ['nullable', 'string', 'max:300'],
                default => ['nullable', 'string', 'max:300'],
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

    #[Computed]
    public function socialiteInstalled(): bool
    {
        return class_exists(\Laravel\Socialite\SocialiteServiceProvider::class);
    }

    public function fallback(string $field): string
    {
        $value = config('dbelo.'.Security::FIELDS[$field]['key']);

        if (is_bool($value)) {
            return $value ? 'on' : 'off';
        }

        return Str::limit((string) $value, 60);
    }

    public function hasSecret(string $field): bool
    {
        return Security::hasSecret($field);
    }

    /**
     * What the captcha is actually doing, in one sentence.
     *
     * The same verdict pattern as the notification bar, for the same reason:
     * five settings decide whether a form is protected, and without one line
     * naming the condition that caught you, "I filled it in and nothing
     * happened" is a guessing game.
     */
    #[Computed]
    public function captchaState(): array
    {
        $captcha = app(Captcha::class);

        if (Security::choice('captcha_provider') === 'off') {
            return ['live' => false, 'why' => 'No provider is selected, so no form is protected.'];
        }

        if (blank(Security::text('captcha_site_key'))) {
            return ['live' => false, 'why' => 'The site key is missing.'];
        }

        if (! Security::hasSecret('captcha_secret')) {
            return ['live' => false, 'why' => 'The secret key is missing.'];
        }

        $on = collect([
            'register' => Security::flag('captcha_register') ? 'sign-up' : null,
            'password' => Security::flag('captcha_password') ? 'password reset' : null,
            'contact' => Security::flag('captcha_contact') ? 'the copyright form' : null,
        ])->filter()->values();

        $after = Security::number('captcha_login_after');

        if ($after > 0) {
            $on->push("login after {$after} failed attempts");
        }

        return [
            'live' => $on->isNotEmpty(),
            'why' => $on->isEmpty()
                ? 'It is configured, but every form is switched off below.'
                : 'Protecting '.$on->join(', ', ' and ').'.',
        ];
    }
}; ?>

<div class="space-y-5">

    @if (! $this->ready)
        <x-admin.migration-pending what="Security settings" table="settings" />
    @else

        @if (session('ok'))
            <div class="rounded-2xl border border-success/25 bg-success/[0.06] px-5 py-3.5 text-[0.86rem] text-paper/75">
                <x-icon name="circle-check" style="solid" class="mr-1.5 text-[0.8rem] text-success" />
                {{ session('ok') }}
            </div>
        @endif

        {{-- ══════════════════════════════════════════════════════════════
             WHAT THIS SCREEN IS NOT
             ══════════════════════════════════════════════════════════════ --}}
        <div class="rounded-2xl border border-hairline bg-panel px-5 py-4">
            <p class="max-w-[84ch] text-[0.82rem] leading-relaxed text-paper/45">
                <x-icon name="shield-halved" style="solid" class="mr-1.5 text-[0.75rem] text-info" />
                This is the <strong class="text-paper/70">deciding</strong> half of Security. The watching half is
                <a href="{{ route('admin.security.access') }}" wire:navigate class="underline underline-offset-2 hover:text-paper">Access log</a>
                and
                <a href="{{ route('admin.security.abuse') }}" wire:navigate class="underline underline-offset-2 hover:text-paper">Blocks &amp; abuse</a>.
                Rate limits and the abuse thresholds are not here on purpose — they live in code, where a change leaves
                a diff, and the abuse screen prints the numbers it is using.
            </p>
        </div>

        <form wire:submit="save"
              x-data="{ dirty: false }"
              x-on:input="dirty = true"
              x-on:change="dirty = true"
              x-on:saved.window="dirty = false"
              class="space-y-5 pb-24">

            @foreach (\App\Support\Security::SECTIONS as $sectionKey => $section)
                <div class="rounded-2xl border border-hairline bg-panel" wire:key="sec-{{ $sectionKey }}">
                    <div class="border-b border-hairline px-5 py-4">
                        <h2 class="text-[0.95rem] font-medium">{{ $section['label'] }}</h2>
                        @if ($section['note'])
                            <p class="mt-0.5 max-w-[76ch] text-[0.78rem] leading-relaxed text-paper/40">{{ $section['note'] }}</p>
                        @endif
                    </div>

                    {{-- ─────────── Per-section verdicts and set-up notes ─────────── --}}

                    @if ($sectionKey === 'captcha')
                        <div class="border-b border-hairline px-5 py-4">
                            @php $state = $this->captchaState; @endphp

                            <div @class([
                                'flex items-start gap-3 rounded-xl px-4 py-3',
                                'bg-success/[0.08]' => $state['live'],
                                'bg-raised' => ! $state['live'],
                            ])>
                                <x-icon :name="$state['live'] ? 'circle-check' : 'shield-slash'" style="solid"
                                        class="mt-[0.15rem] shrink-0 text-[0.8rem] {{ $state['live'] ? 'text-success' : 'text-paper/30' }}" />

                                <div class="min-w-0">
                                    <div class="text-[0.86rem] text-paper/80">
                                        {{ $state['live'] ? 'The captcha is on.' : 'No form is protected.' }}
                                    </div>
                                    <p class="mt-1 max-w-[70ch] text-[0.8rem] leading-relaxed text-paper/45">{{ $state['why'] }}</p>
                                </div>
                            </div>
                        </div>
                    @endif

                    @if ($sectionKey === 'google')
                        <div class="border-b border-hairline px-5 py-4 space-y-3">
                            @if (! $this->socialiteInstalled)
                                <div class="flex items-start gap-3 rounded-xl bg-warning/[0.08] px-4 py-3">
                                    <x-icon name="triangle-exclamation" style="solid" class="mt-[0.15rem] shrink-0 text-[0.8rem] text-warning" />
                                    <div class="min-w-0">
                                        <div class="text-[0.86rem] text-paper/80">The package is not installed.</div>
                                        <code class="mt-2 inline-block rounded-lg bg-rail px-3 py-1.5 font-mono text-[0.78rem] text-paper/80">composer require laravel/socialite</code>
                                    </div>
                                </div>
                            @endif

                            {{-- The one value Google needs from us, shown so it
                                 can be copied. Derived from APP_URL, never
                                 stored: a second copy in the table would be a
                                 second definition that disagrees in silence. --}}
                            <div class="rounded-xl bg-rail px-4 py-3">
                                <div class="text-[0.68rem] uppercase tracking-[0.14em] text-paper/30">Authorised redirect URI</div>
                                <code class="mt-1 block break-all font-mono text-[0.8rem] text-paper/75">{{ \App\Support\Security::googleCallback() }}</code>
                                <p class="mt-2 max-w-[74ch] text-[0.78rem] leading-relaxed text-paper/40">
                                    Paste this into Google Cloud Console → Credentials → your OAuth client.
                                    <strong class="text-warning/90">Google will not accept a <code class="font-mono">.test</code> address</strong> —
                                    it allows <code class="font-mono">http://</code> only for localhost, so this can be finished but not
                                    tested until dbelo.com is live.
                                </p>
                            </div>
                        </div>
                    @endif

                    <div class="divide-y divide-hairline">
                        @foreach ($section['fields'] as $field)
                            @php $meta = \App\Support\Security::FIELDS[$field]; @endphp

                            <div wire:key="f-{{ $field }}">
                                <x-admin.setting-field
                                    :label="$meta['label']"
                                    :name="'values.'.$field"
                                    :affects="$meta['help'] ?: null">

                                    @switch($meta['type'])

                                        @case('bool')
                                            @php $isOn = ($values[$field] ?? '1') === '1'; @endphp

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

                                        @case('secret')
                                            @if ($this->hasSecret($field))
                                                <div class="flex flex-wrap items-center gap-3">
                                                    <span class="inline-flex items-center gap-2 rounded-lg bg-success/10 px-3 py-2 text-[0.8rem] text-success">
                                                        <x-icon name="lock" style="solid" class="text-[0.7rem]" />
                                                        Saved and encrypted
                                                    </span>

                                                    <button type="button" wire:click="forgetSecret('{{ $field }}')"
                                                            class="text-[0.78rem] text-paper/35 underline-offset-2 transition hover:text-danger hover:underline">
                                                        Remove
                                                    </button>
                                                </div>

                                                <input type="password" wire:model="values.{{ $field }}" autocomplete="new-password"
                                                       placeholder="Type a new one to replace it"
                                                       class="mt-2.5 w-full rounded-lg border-0 bg-raised px-3 py-2.5 font-mono text-[0.8rem] focus:outline-none focus:ring-1 focus:ring-brand" />
                                            @else
                                                <input type="password" wire:model="values.{{ $field }}" autocomplete="new-password"
                                                       placeholder="Not set"
                                                       class="w-full rounded-lg border-0 bg-raised px-3 py-2.5 font-mono text-[0.8rem] focus:outline-none focus:ring-1 focus:ring-brand" />
                                            @endif
                                            @break

                                        @case('select')
                                            <select wire:model.live="values.{{ $field }}"
                                                    class="w-full rounded-lg border-0 bg-raised px-3 py-2.5 text-[0.84rem] focus:outline-none focus:ring-1 focus:ring-brand">
                                                <option value="">Default — {{ $meta['options'][$this->fallback($field)] ?? $this->fallback($field) }}</option>

                                                @foreach ($meta['options'] as $value => $caption)
                                                    <option value="{{ $value }}" wire:key="{{ $field }}-{{ $value }}">{{ $caption }}</option>
                                                @endforeach
                                            </select>
                                            @break

                                        @case('number')
                                            <input type="number" min="0" max="20" wire:model.live.debounce.500ms="values.{{ $field }}"
                                                   placeholder="{{ $this->fallback($field) }}"
                                                   class="w-28 rounded-lg border-0 bg-raised px-3 py-2.5 text-[0.84rem] focus:outline-none focus:ring-1 focus:ring-brand" />
                                            @break

                                        @default
                                            <input type="text" wire:model.live.debounce.500ms="values.{{ $field }}" maxlength="300"
                                                   autocomplete="off" spellcheck="false"
                                                   placeholder="{{ $this->fallback($field) }}"
                                                   class="w-full rounded-lg border-0 bg-raised px-3 py-2.5 font-mono text-[0.8rem] focus:outline-none focus:ring-1 focus:ring-brand" />
                                    @endswitch
                                </x-admin.setting-field>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach

            <p class="px-1 text-[0.78rem] text-paper/30">
                Every change is written to the activity log. Secrets are logged as "replaced" — never with their value.
            </p>

            <x-admin.save-bar />
        </form>
    @endif
</div>
