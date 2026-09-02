<?php

use App\Models\ActivityLog;
use App\Models\Setting;
use App\Support\Email;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.admin')] #[Title('Email & SMTP')] class extends Component {
    /** @var array<string, string> */
    public array $values = [];

    /** The outcome of the last test send: ['ok' => bool, 'message' => string] */
    public ?array $testResult = null;

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $stored = Setting::cached();

        foreach (Email::FIELDS as $field => $meta) {
            // A secret is never loaded, not even to be re-saved unchanged.
            // See the same handling on the Security screen for why.
            if ($meta['type'] === 'secret') {
                $this->values[$field] = '';

                continue;
            }

            $saved = (string) ($stored[$meta['key']] ?? '');

            $this->values[$field] = $meta['type'] === 'bool' && $saved === ''
                ? Email::defaultValue($field)
                : $saved;
        }
    }

    public function save(): void
    {
        $this->validate($this->rules());

        $before = Setting::cached();
        $changed = 0;

        foreach (Email::FIELDS as $field => $meta) {
            $new = trim((string) ($this->values[$field] ?? ''));

            if ($meta['type'] === 'secret') {
                if ($new === '') {
                    continue;
                }

                Setting::put($meta['key'], $new, 'mail', encrypted: true);

                ActivityLog::record('settings.updated', null,
                    'Email — '.$meta['label'].' replaced',
                    ['setting' => $meta['key']]);

                $this->values[$field] = '';
                $changed++;

                continue;
            }

            $old = (string) ($before[$meta['key']] ?? '');

            if ($new === Email::defaultValue($field)) {
                $new = '';
            }

            if ($new === $old) {
                continue;
            }

            Setting::put($meta['key'], $new === '' ? null : $new, 'mail');

            ActivityLog::record('settings.updated', null,
                'Email — '.$meta['label'].($new === '' ? ' reset to the default' : ' changed'),
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

    /**
     * Switch the transport from inside the section it belongs to.
     *
     * The select at the top is still the one control that decides. This is
     * the same decision offered where the question actually occurs to you —
     * you are looking at the SMTP fields, so "use these" is the thing you
     * want, not "scroll up and find the dropdown that reveals them".
     */
    public function useTransport(string $transport, bool $on = true): void
    {
        if (! array_key_exists($transport, Email::FIELDS['mailer']['options'])) {
            return;
        }

        /*
         * Turning one on turns the other off, and that is not a shortcut —
         * a message leaves by one carrier or the other, never both. Two
         * independent switches would let you set a state the mail system
         * cannot be in, and then quietly pick one for you.
         *
         * Off means Log. Not a hidden fourth setting: "no carrier is
         * switched on" already means nothing is delivered, and the strip at
         * the top of the screen says exactly that.
         */
        $this->values['mailer'] = $on ? $transport : 'log';
    }

    public function setFlag(string $field, string $value): void
    {
        if (array_key_exists($field, Email::FIELDS) && in_array($value, ['0', '1'], true)) {
            $this->values[$field] = $value;
        }
    }

    public function forgetSecret(string $field): void
    {
        if ((Email::FIELDS[$field]['type'] ?? '') !== 'secret') {
            return;
        }

        Setting::put(Email::FIELDS[$field]['key'], null, 'mail');

        ActivityLog::record('settings.updated', null,
            'Email — '.Email::FIELDS[$field]['label'].' removed',
            ['setting' => Email::FIELDS[$field]['key']]);

        session()->flash('ok', Email::FIELDS[$field]['label'].' removed.');
    }

    /**
     * Save, then try to send one real email, and say exactly what happened.
     *
     * THE MOST IMPORTANT CONTROL ON THIS SCREEN, and the reason is what
     * happens without it: you fill in five SMTP fields, they are wrong in
     * one character, and you find out weeks later when somebody cannot
     * reset their password and never tells you. Mail is the one part of a
     * site that fails completely silently for the operator.
     *
     * Saving first rather than testing what is typed, because a test that
     * passes against unsaved values and then fails in production is worse
     * than no test. One button, one meaning: this is the configuration the
     * site will actually use.
     *
     * The real exception message is shown, not "sending failed". "Connection
     * could not be established with host smtp.example.com: Connection
     * refused" tells you the port is wrong in three seconds; "failed" starts
     * an afternoon.
     */
    public function sendTest(): void
    {
        $this->save();

        $to = Email::alertRecipient();

        if (! $to) {
            $this->testResult = [
                'ok' => false,
                'message' => 'There is no admin email to send to. Set one in Settings → General first.',
            ];

            return;
        }

        // Fresh settings, this request: apply() normally runs at boot, which
        // was before the values above were saved.
        Email::apply();

        $label = Email::status()['label'];

        try {
            Mail::send('mail.alert', [
                'heading' => 'Test email',
                'body' => "If you are reading this, mail is leaving the site correctly.\n\n"
                    .'Sent from Settings → Email & SMTP at '.now()->format('H:i, j F Y').'.',
                'url' => route('admin.settings.email'),
                'action' => 'Back to the settings',
            ], function ($message) use ($to) {
                $message->to($to)->subject('['.config('app.name').'] Test email');
            });

            /*
             * THREE outcomes, not two.
             *
             * The first version showed a green "It went out" for a message
             * that had only been written to a file — which is the opposite
             * of the truth, and it cost somebody a wait for an email that
             * was never going to arrive. A test that reports success for
             * "nothing was sent" is worse than no test: it converts an
             * unconfigured mailer into a configured one, in your head.
             */
            $state = Email::sends() ? 'sent' : 'logged';

            $this->testResult = [
                'state' => $state,
                'message' => $state === 'sent'
                    ? "Handed to {$label} for {$to}. If it has not arrived in a minute, look in spam before changing anything here — a first email from a new sender often lands there."
                    : "Nothing was sent. The transport is Log, so the message was written to storage/logs/laravel.log and went no further. Choose SMTP or Resend above to actually deliver it.",
            ];
        } catch (\Throwable $e) {
            $state = 'failed';
            $this->testResult = ['state' => 'failed', 'message' => $e->getMessage()];
        }

        Email::recordTest($state);
    }

    /** @return array<string, array<int, mixed>> */
    private function rules(): array
    {
        $rules = [];

        foreach (Email::FIELDS as $field => $meta) {
            $rules["values.{$field}"] = match ($meta['type']) {
                'bool' => ['nullable', 'in:0,1'],
                'number' => ['nullable', 'integer', 'between:1,65535'],
                'select' => ['nullable', Rule::in(array_keys($meta['options']))],
                'secret' => ['nullable', 'string', 'max:300'],
                default => ['nullable', 'string', 'max:200'],
            };
        }

        // The two addresses are addresses, and a typo here is silence.
        $rules['values.from_address'][] = 'email';
        $rules['values.marketing_from'][] = 'email';

        return $rules;
    }

    /* ═════════════════════════════ Helpers ═════════════════════════════ */

    #[Computed]
    public function ready(): bool
    {
        return Schema::hasTable('settings');
    }

    public function fallback(string $field): string
    {
        $value = config('dbelo.'.Email::FIELDS[$field]['key']);

        if (is_bool($value)) {
            return $value ? 'on' : 'off';
        }

        return Str::limit((string) $value, 60);
    }

    public function hasSecret(string $field): bool
    {
        return Email::hasSecret($field);
    }

    /** Which transport section is worth showing. */
    public function transport(): string
    {
        return $this->values['mailer'] ?: Email::choice('mailer');
    }
}; ?>

<div class="space-y-5">

    @if (! $this->ready)
        <x-admin.migration-pending what="Email settings" table="settings" />
    @else

        @if (session('ok'))
            <div class="rounded-2xl border border-success/25 bg-success/[0.06] px-5 py-3.5 text-[0.86rem] text-paper/75">
                <x-icon name="circle-check" style="solid" class="mr-1.5 text-[0.8rem] text-success" />
                {{ session('ok') }}
            </div>
        @endif

        {{-- ══════════════════════════════════════════════════════════════
             THE TEST

             First on the screen, not last. It is the only thing here that
             tells you the truth, and burying it under fifteen fields means
             it gets used once and then forgotten.
             ══════════════════════════════════════════════════════════════ --}}
        {{-- ══════════════════════════════════════════════════════════════
             WHAT IS CARRYING MAIL RIGHT NOW

             Added because "how do I know SMTP is enabled?" was a fair
             question the screen could not answer. The select shows what you
             CHOSE; a choice with no host and no password delivers nothing.
             Chosen and working are two different states, and only one of
             them was on screen.
             ══════════════════════════════════════════════════════════════ --}}
        @php $status = \App\Support\Email::status(); @endphp

        <div @class([
            'rounded-2xl border px-5 py-4',
            'border-success/25 bg-success/[0.06]' => $status['ready'],
            'border-warning/25 bg-warning/[0.06]' => ! $status['ready'],
        ])>
            <div class="flex items-start gap-3.5">
                <span @class([
                    'grid size-9 shrink-0 place-items-center rounded-full',
                    'bg-success/15 text-success' => $status['ready'],
                    'bg-warning/15 text-warning' => ! $status['ready'],
                ])>
                    <x-icon :name="$status['ready'] ? 'paper-plane' : 'file-lines'" style="solid" class="text-[0.85rem]" />
                </span>

                <div class="min-w-0 flex-1">
                    <div class="text-[0.95rem] font-medium">
                        {{ $status['ready'] ? 'Mail is being delivered' : 'Mail is not being delivered' }}
                        <span class="font-normal text-paper/45">— {{ $status['label'] }}</span>
                    </div>

                    <p class="mt-1 max-w-[76ch] break-words text-[0.82rem] leading-relaxed text-paper/50">
                        {{ $status['detail'] }}
                    </p>

                    <div class="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-[0.78rem] text-paper/35">
                        @if ($status['fallback'])
                            <span>
                                <x-icon name="arrow-turn-down-right" style="solid" class="mr-1 text-[0.7rem]" />
                                Falls back to {{ $status['fallback'] }}
                            </span>
                        @endif

                        {{-- A test you ran once and cannot see afterwards
                             proves nothing tomorrow. --}}
                        @if ($status['last_test'])
                            @php $t = $status['last_test']; @endphp
                            <span>
                                Last test
                                <span @class([
                                    'text-success' => $t['state'] === 'sent',
                                    'text-warning' => $t['state'] === 'logged',
                                    'text-danger' => $t['state'] === 'failed',
                                ])>{{ ['sent' => 'sent', 'logged' => 'only logged', 'failed' => 'failed'][$t['state']] ?? $t['state'] }}</span>
                                {{ \Illuminate\Support\Carbon::parse($t['at'])->diffForHumans() }}
                            </span>
                        @else
                            <span>Never tested</span>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <div class="rounded-2xl border border-hairline bg-panel">
            <div class="flex flex-wrap items-start justify-between gap-4 px-5 py-5">
                <div class="min-w-0">
                    <h2 class="text-[0.95rem] font-medium">Does it actually send?</h2>
                    <p class="mt-1 max-w-[70ch] text-[0.8rem] leading-relaxed text-paper/45">
                        Saves what is on this screen and then sends one real email to
                        <span class="text-paper/70">{{ \App\Support\Email::alertRecipient() ?? 'nobody — set an admin email in General' }}</span>.
                        Mail is the one part of a site that fails in complete silence: without this you find out weeks
                        later, from somebody who could not reset their password and never told you.
                    </p>
                </div>

                <button type="button" wire:click="sendTest" wire:loading.attr="disabled" wire:target="sendTest"
                        class="shrink-0 rounded-xl bg-brand px-5 py-2.5 text-[0.82rem] text-white transition hover:brightness-110 disabled:opacity-50">
                    <span wire:loading.remove wire:target="sendTest">Save and send a test</span>
                    <span wire:loading wire:target="sendTest">Sending…</span>
                </button>
            </div>

            @if ($testResult)
                @php
                    [$tint, $icon, $iconTint, $headline] = match ($testResult['state']) {
                        'sent' => ['bg-success/[0.06]', 'paper-plane', 'text-success', 'It left the server.'],
                        'logged' => ['bg-warning/[0.06]', 'file-lines', 'text-warning', 'Nothing was sent.'],
                        default => ['bg-danger/[0.06]', 'circle-xmark', 'text-danger', 'It did not send.'],
                    };
                @endphp

                <div class="border-t border-hairline px-5 py-4 {{ $tint }}">
                    <div class="flex items-start gap-3">
                        <x-icon :name="$icon" style="solid" class="mt-[0.15rem] shrink-0 text-[0.8rem] {{ $iconTint }}" />

                        <div class="min-w-0">
                            <div class="text-[0.86rem] text-paper/80">{{ $headline }}</div>

                            {{-- The real exception text, not a friendly
                                 summary. "Connection refused" names the
                                 problem in three seconds; "sending failed"
                                 starts an afternoon. --}}
                            <p class="mt-1 max-w-[86ch] break-words font-mono text-[0.78rem] leading-relaxed text-paper/50">
                                {{ $testResult['message'] }}
                            </p>
                        </div>
                    </div>
                </div>
            @endif
        </div>

        <form wire:submit="save"
              x-data="{ dirty: false }"
              x-on:input="dirty = true"
              x-on:change="dirty = true"
              x-on:saved.window="dirty = false"
              class="space-y-5 pb-24">

            @foreach (\App\Support\Email::SECTIONS as $sectionKey => $section)
                @php
                    /*
                     * NOT HIDDEN when it is not the active transport, which
                     * is what the first version did.
                     *
                     * Hiding them meant opening this screen and finding no
                     * SMTP fields at all — because the transport starts at
                     * Log — and no clue that choosing SMTP above would
                     * reveal them. A control you have to discover in order
                     * to discover another control is a screen that has
                     * failed, and it had already caused exactly that
                     * confusion once on the notification bar.
                     *
                     * They stay visible and stay editable: filling SMTP in
                     * and THEN switching is the order most people work in,
                     * and the test button is right there to prove it before
                     * anything depends on it.
                     */
                    $isTransportSection = in_array($sectionKey, ['smtp', 'resend'], true);
                    $inactive = $isTransportSection && $this->transport() !== $sectionKey;
                @endphp

                <div @class([
                        'rounded-2xl border bg-panel',
                        'border-hairline' => ! $inactive,
                        'border-hairline/60' => $inactive,
                     ]) wire:key="sec-{{ $sectionKey }}">
                    <div class="flex flex-wrap items-start justify-between gap-4 border-b border-hairline px-5 py-4">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2.5">
                                <h2 class="text-[0.95rem] font-medium">{{ $section['label'] }}</h2>

                                {{-- The state, in a word, next to the name.
                                     "Is SMTP on?" should be answerable from
                                     the heading — without reading a
                                     sentence, and without scrolling to a
                                     dropdown somewhere else. --}}
                                @if ($isTransportSection)
                                    <span @class([
                                        'rounded-full px-2.5 py-0.5 text-[0.66rem] uppercase tracking-[0.12em]',
                                        'bg-success/15 text-success' => ! $inactive,
                                        'bg-paper/[0.07] text-paper/35' => $inactive,
                                    ])>{{ $inactive ? 'Off' : 'In use' }}</span>
                                @endif
                            </div>

                            @if ($section['note'])
                                <p class="mt-0.5 max-w-[70ch] text-[0.78rem] leading-relaxed text-paper/40">{{ $section['note'] }}</p>
                            @endif
                        </div>

                        {{-- The same On/Off switch as every other toggle in
                             this panel, so it reads as one thing. There is no
                             separate dropdown any more: this IS the control
                             that decides how mail leaves the site. --}}
                        @if ($isTransportSection)
                            <div class="inline-flex shrink-0 rounded-xl bg-raised p-1">
                                <button type="button" x-on:click="dirty = true"
                                        wire:click="useTransport('{{ $sectionKey }}', true)"
                                        @class([
                                            'rounded-lg px-6 py-1.5 text-sm transition',
                                            'bg-success text-ink' => ! $inactive,
                                            'text-paper/45 hover:text-paper' => $inactive,
                                        ])>On</button>

                                <button type="button" x-on:click="dirty = true"
                                        wire:click="useTransport('{{ $sectionKey }}', false)"
                                        @class([
                                            'rounded-lg px-6 py-1.5 text-sm transition',
                                            'bg-rail text-paper' => $inactive,
                                            'text-paper/45 hover:text-paper' => ! $inactive,
                                        ])>Off</button>
                            </div>
                        @endif
                    </div>

                    @if ($inactive)
                        <div class="border-b border-hairline px-5 py-4">
                            <p class="rounded-xl bg-raised px-4 py-3 text-[0.82rem] leading-relaxed text-paper/45">
                                Nothing is being delivered through {{ $section['label'] }} right now. The fields below
                                stay editable — fill them in first, then switch it on and send a test.
                            </p>
                        </div>
                    @endif

                    @if ($sectionKey === 'transport' && $this->transport() === 'log')
                        <div class="border-b border-hairline px-5 py-4">
                            <div class="flex items-start gap-3 rounded-xl bg-warning/[0.08] px-4 py-3">
                                <x-icon name="triangle-exclamation" style="solid" class="mt-[0.15rem] shrink-0 text-[0.8rem] text-warning" />
                                <div class="min-w-0">
                                    <div class="text-[0.86rem] text-paper/80">Nothing is being sent.</div>
                                    <p class="mt-1 max-w-[74ch] text-[0.8rem] leading-relaxed text-paper/45">
                                        Neither SMTP nor Resend is switched on, so every email is written to
                                        <span class="font-mono">storage/logs/laravel.log</span> and goes no further. Correct while
                                        building. Left like this when the site is public, no contributor is ever told their sound
                                        was approved and no password reset ever arrives.
                                    </p>
                                </div>
                            </div>
                        </div>
                    @endif

                    <div class="divide-y divide-hairline">
                        @foreach ($section['fields'] as $field)
                            @php $meta = \App\Support\Email::FIELDS[$field]; @endphp

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
                                            <input type="number" min="1" max="65535" wire:model.live.debounce.500ms="values.{{ $field }}"
                                                   placeholder="{{ $this->fallback($field) }}"
                                                   class="w-32 rounded-lg border-0 bg-raised px-3 py-2.5 text-[0.84rem] focus:outline-none focus:ring-1 focus:ring-brand" />
                                            @break

                                        @default
                                            <input type="text" wire:model.live.debounce.500ms="values.{{ $field }}" maxlength="200"
                                                   autocomplete="off" spellcheck="false"
                                                   placeholder="{{ $this->fallback($field) }}"
                                                   class="w-full rounded-lg border-0 bg-raised px-3 py-2.5 text-[0.84rem] focus:outline-none focus:ring-1 focus:ring-brand" />
                                    @endswitch
                                </x-admin.setting-field>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach

            {{-- ══════════════════════════════════════════════════════════
                 WHAT IS NOT HERE
                 ══════════════════════════════════════════════════════════ --}}
            <div class="rounded-2xl border border-hairline bg-panel px-5 py-4">
                <p class="max-w-[84ch] text-[0.8rem] leading-relaxed text-paper/45">
                    <x-icon name="circle-info" style="solid" class="mr-1 text-[0.72rem] text-info" />
                    <strong class="text-paper/65">There is no email log, and that is a choice with a condition on it.</strong>
                    Resend keeps one, with the bounces and spam complaints this server cannot see from the inside. On
                    plain SMTP nobody keeps one — you would be sending into the dark and hearing about failures only
                    when somebody complains. Worth knowing before choosing the transport, not after.
                </p>
            </div>

            <x-admin.save-bar />
        </form>
    @endif
</div>
