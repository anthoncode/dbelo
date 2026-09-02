<?php

use App\Models\ActivityLog;
use App\Models\Setting;
use App\Providers\AppServiceProvider;
use App\Support\SiteStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.admin')] #[Title('General settings')] class extends Component {
    /*
    | Property → setting key.
    |
    | Livewire binds to flat properties; the table is keyed by dotted paths
    | that mirror config/dbelo.php. This map is the only place the two names
    | meet, so renaming a field is one line rather than a search.
    |
    | support_email is deliberately `legal.support_email` and not a new key
    | of its own: the terms, the privacy policy and the copyright form
    | already read that one. A second "support email" beside it would be two
    | definitions of one fact, which is the drift that made site_url a bad
    | idea — and this one fails quietly, by printing the old address on the
    | legal pages nobody re-reads.
    */
    private const KEYS = [
        'siteName' => 'site.name',
        'siteDescription' => 'site.description',
        'footer' => 'site.footer',
        'supportEmail' => 'legal.support_email',
        'adminEmail' => 'site.admin_email',
        'timezone' => 'timezone',
        'statusMessage' => 'site.status_message',
    ];

    private const LABELS = [
        'siteName' => 'Site name',
        'siteDescription' => 'Site description',
        'footer' => 'Footer text',
        'supportEmail' => 'Support email',
        'adminEmail' => 'Admin email',
        'timezone' => 'Timezone',
        'statusMessage' => 'Closed-page message',
    ];

    public string $siteName = '';

    public string $siteDescription = '';

    public string $footer = '';

    public string $supportEmail = '';

    public string $adminEmail = '';

    public string $timezone = '';

    public string $statusMessage = '';

    /* The site status is saved on its own — see setStatus(). */
    public string $status = SiteStatus::LIVE;

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $stored = Setting::cached();

        foreach (self::KEYS as $property => $key) {
            // The STORED value, not the effective one. Prefilling with the
            // config default would write every default into the table on the
            // first save and make "leave empty to use the default" a lie —
            // the box would never be empty again.
            $this->{$property} = (string) ($stored[$key] ?? '');
        }

        $this->status = SiteStatus::current();
    }

    /* ═════════════════════════════ The fields ═════════════════════════════ */

    public function save(): void
    {
        $data = $this->validate([
            // Nothing is `required`. Empty means "use the default in
            // config/dbelo.php", which is a valid answer for every one of
            // these and the reason the table only holds what was changed.
            'siteName' => ['nullable', 'string', 'max:60'],
            'siteDescription' => ['nullable', 'string', 'max:160'],
            'footer' => ['nullable', 'string', 'max:300'],
            'supportEmail' => ['nullable', 'email', 'max:120'],
            'adminEmail' => ['nullable', 'email', 'max:120'],
            'timezone' => ['nullable', 'string', 'timezone'],
            'statusMessage' => ['nullable', 'string', 'max:200'],
        ]);

        // Snapshot once. Every put() drops the cache, so reading inside the
        // loop would query the table again on each field.
        $before = Setting::cached();
        $changed = 0;

        foreach (self::KEYS as $property => $key) {
            $new = trim((string) $data[$property]);
            $old = (string) ($before[$key] ?? '');

            if ($new === $old) {
                continue;
            }

            Setting::put($key, $new === '' ? null : $new, 'general');

            // One row per field, with the pair the activity screen renders
            // specially. "What it was before" is the whole value of an audit
            // entry about a setting: the question is always asked afterwards,
            // by someone trying to undo it.
            ActivityLog::record(
                'settings.updated',
                null,
                self::LABELS[$property].($new === '' ? ' reset to the default' : ' changed'),
                ['setting' => $key, 'from' => $old ?: null, 'to' => $new ?: null],
            );

            $changed++;
        }

        unset($this->localTime);

        session()->flash('ok', match ($changed) {
            0 => 'Nothing had changed.',
            1 => 'Saved.',
            default => "Saved — {$changed} settings changed.",
        });
    }

    /* ═════════════════════════════ The door ═════════════════════════════ */

    /**
     * Saved on its own, and immediately.
     *
     * It is the one control here with a consequence outside this screen:
     * everything else changes wording, this one decides whether anybody can
     * reach the site. Burying it inside a Save button that also renames the
     * footer is how it gets changed by accident.
     */
    public function setStatus(string $status): void
    {
        if (! array_key_exists($status, SiteStatus::modes())) {
            return;
        }

        $from = SiteStatus::current();

        if ($from === $status) {
            return;
        }

        Setting::put('site.status', $status, 'general');

        ActivityLog::record(
            'site.status.changed',
            null,
            'Site set to '.SiteStatus::modes()[$status]['label'],
            ['from' => $from, 'to' => $status],
        );

        $this->status = $status;

        session()->flash('ok', $status === SiteStatus::LIVE
            ? 'The site is open.'
            : 'The site is closed to visitors. You still see it because you are an admin.');
    }

    /* ═════════════════════════════ Helpers ═════════════════════════════ */

    #[Computed]
    public function ready(): bool
    {
        return Schema::hasTable('settings');
    }

    /** What the site falls back to for a field left empty. */
    public function fallback(string $property): string
    {
        return (string) config('dbelo.'.self::KEYS[$property], '');
    }

    /**
     * Is this field stored for something that does not exist yet?
     *
     * Read from the same list Diagnostics checks against, never repeated
     * here. Two lists would disagree the first time one was updated, and the
     * disagreement would take the form of a field claiming to work.
     */
    public function pending(string $property): bool
    {
        return array_key_exists(self::KEYS[$property], AppServiceProvider::NOT_YET_CONSUMED);
    }

    /**
     * The clock in the selected zone.
     *
     * A timezone name means nothing on its own — "America/La_Paz" does not
     * tell you whether you just moved the day boundary four hours. The
     * current time there does, in one glance.
     */
    #[Computed]
    public function localTime(): ?string
    {
        $zone = $this->timezone ?: $this->fallback('timezone');

        if (blank($zone)) {
            return null;
        }

        return rescue(fn () => Carbon::now($zone)->format('H:i, l j F'), null, false);
    }

    /** Grouped so a list of four hundred entries can be scanned. */
    #[Computed]
    public function zones(): array
    {
        $grouped = [];

        foreach (timezone_identifiers_list() as $zone) {
            $region = str_contains($zone, '/') ? strtok($zone, '/') : 'Other';
            $grouped[$region][] = $zone;
        }

        ksort($grouped);

        return $grouped;
    }
}; ?>

<div class="space-y-5">

    @if (! $this->ready)
        <x-admin.migration-pending
            what="General settings"
            table="settings" />
    @else

        @if (session('ok'))
            <div class="rounded-2xl border border-success/25 bg-success/[0.06] px-5 py-3.5 text-[0.86rem] text-paper/75">
                <x-icon name="circle-check" style="solid" class="mr-1.5 text-[0.8rem] text-success" />
                {{ session('ok') }}
            </div>
        @endif

        {{-- ══════════════════════════════════════════════════════════════
             THE DOOR

             First, and on its own, because it is the only control on this
             screen that can make the site unreachable — and because "coming
             soon" is the state he is actually in until dbelo.com is published.
             ══════════════════════════════════════════════════════════════ --}}
        <div class="rounded-2xl border border-hairline bg-panel">
            <div class="border-b border-hairline px-5 py-4">
                <h2 class="text-[0.95rem] font-medium">Who can reach the site</h2>
                <p class="mt-0.5 max-w-[74ch] text-[0.78rem] leading-relaxed text-paper/40">
                    Takes effect on the next request. Admins always pass through, whichever of these is chosen —
                    a switch that can lock you out of the panel that holds the switch is a switch you only get to
                    use once.
                </p>
            </div>

            <div class="grid gap-3 px-5 py-5 sm:grid-cols-3">
                @foreach (\App\Support\SiteStatus::modes() as $key => $mode)
                    @php
                        $active = $this->status === $key;

                        /*
                         * Written out in full, never assembled from $mode['tone'].
                         *
                         * Tailwind emits a class only if it saw the whole
                         * string in a source file at build time. "bg-" . $tone
                         * exists at runtime and has never existed at build
                         * time, so the card would render with no colour at
                         * all — the same silent failure as a stale build,
                         * and just as hard to see from the outside.
                         */
                        [$card, $chip] = match ($key) {
                            \App\Support\SiteStatus::LIVE => ['border-success/40 bg-success/[0.08]', 'bg-success/15 text-success'],
                            \App\Support\SiteStatus::SOON => ['border-info/40 bg-info/[0.08]', 'bg-info/15 text-info'],
                            default => ['border-warning/40 bg-warning/[0.08]', 'bg-warning/15 text-warning'],
                        };
                    @endphp

                    <button type="button" wire:click="setStatus('{{ $key }}')" wire:key="mode-{{ $key }}"
                            @class([
                                'rounded-xl border px-4 py-4 text-left transition',
                                $card => $active,
                                'border-hairline bg-raised hover:border-paper/20' => ! $active,
                            ])>
                        <div class="flex items-center gap-2.5">
                            <span @class([
                                'grid size-8 shrink-0 place-items-center rounded-full',
                                $chip => $active,
                                'bg-paper/[0.06] text-paper/40' => ! $active,
                            ])>
                                <x-icon :name="$mode['icon']" style="solid" class="text-[0.8rem]" />
                            </span>

                            <span class="text-[0.9rem] {{ $active ? 'text-paper' : 'text-paper/70' }}">
                                {{ $mode['label'] }}
                            </span>

                            @if ($active)
                                <span class="ml-auto text-[0.68rem] uppercase tracking-[0.16em] text-paper/30">Now</span>
                            @endif
                        </div>

                        <p class="mt-2.5 text-[0.78rem] leading-relaxed text-paper/40">{{ $mode['blurb'] }}</p>
                    </button>
                @endforeach
            </div>

            @if ($this->status !== \App\Support\SiteStatus::LIVE)
                <div class="border-t border-hairline">
                    <form wire:submit="save">
                        <x-admin.setting-field
                            label="What the closed page says"
                            name="statusMessage"
                            affects="One line on the page visitors get instead of the site. Leave it empty for the standard wording.">
                            <input type="text" wire:model="statusMessage" maxlength="200"
                                   placeholder="Opening in September. Follow @dbelo for the date."
                                   class="w-full rounded-lg border-0 bg-raised px-3 py-2.5 text-[0.84rem] focus:outline-none focus:ring-1 focus:ring-brand" />
                        </x-admin.setting-field>

                        <div class="flex justify-end px-5 pb-5">
                            <button type="submit"
                                    class="rounded-lg bg-brand px-4 py-2.5 text-[0.82rem] text-white transition hover:brightness-110">
                                Save message
                            </button>
                        </div>
                    </form>
                </div>
            @endif
        </div>

        {{-- ══════════════════════════════════════════════════════════════
             IDENTITY

             Six fields. The plan had ten; three were dropped and the reasons
             are written at the bottom of this screen rather than in a commit
             message nobody will read.
             ══════════════════════════════════════════════════════════════ --}}
        <form wire:submit="save">
            <div class="rounded-2xl border border-hairline bg-panel">
                <div class="border-b border-hairline px-5 py-4">
                    <h2 class="text-[0.95rem] font-medium">Identity</h2>
                    <p class="mt-0.5 max-w-[74ch] text-[0.78rem] leading-relaxed text-paper/40">
                        Everything here is text the public reads. An empty box is not a missing value — it means the
                        default in <span class="font-mono text-paper/55">config/dbelo.php</span> applies, which is
                        what a fresh install runs on.
                    </p>
                </div>

                <div class="divide-y divide-hairline">
                    <x-admin.setting-field
                        label="Site name"
                        name="siteName"
                        :default="$this->fallback('siteName')"
                        affects="The browser tab, the og:site_name for shared links, the copyright line in the footer, and the sender name on every email.">
                        <input type="text" wire:model="siteName" maxlength="60"
                               class="w-full rounded-lg border-0 bg-raised px-3 py-2.5 text-[0.84rem] focus:outline-none focus:ring-1 focus:ring-brand" />
                    </x-admin.setting-field>

                    <x-admin.setting-field
                        label="Site description"
                        name="siteDescription"
                        :default="$this->fallback('siteDescription')"
                        affects="The sentence under the title in Google's results, and the preview when a link is shared. Used by the homepage and by every page that has nothing narrower to say."
                        :used-by="['The homepage', 'Blog posts and pages with no description of their own', 'Anything else that sets none']"
                        :not-used-by="['/sounds and each category — they describe a catalogue', '/packs — describes the packs', 'A sound or post page — describes that record']">
                        <textarea wire:model.live="siteDescription" rows="2" maxlength="160"
                                  class="w-full resize-none rounded-lg border-0 bg-raised px-3 py-2.5 text-[0.84rem] leading-relaxed focus:outline-none focus:ring-1 focus:ring-brand"></textarea>

                        {{-- 160 is where Google truncates. Counting up to it
                             beats discovering the cut on the results page. --}}
                        @php $length = mb_strlen($siteDescription); @endphp
                        <div class="mt-1.5 flex items-center justify-between text-[0.74rem]">
                            <span class="text-paper/25">
                                @if ($length === 0)
                                    Around 150 characters reads best.
                                @elseif ($length < 70)
                                    Short — there is room for more.
                                @else
                                    Good length.
                                @endif
                            </span>
                            <span @class([
                                'tabular-nums',
                                'text-paper/30' => $length <= 155,
                                'text-warning' => $length > 155,
                            ])>{{ $length }}/160</span>
                        </div>
                    </x-admin.setting-field>

                    <x-admin.setting-field
                        label="Footer text"
                        name="footer"
                        :default="$this->fallback('footer')"
                        affects="A short paragraph above the footer links. Not indexed and not a description — this one is for people. Left empty, the footer simply does not show it.">
                        <textarea wire:model="footer" rows="3" maxlength="300"
                                  class="w-full resize-none rounded-lg border-0 bg-raised px-3 py-2.5 text-[0.84rem] leading-relaxed focus:outline-none focus:ring-1 focus:ring-brand"></textarea>
                    </x-admin.setting-field>

                    <x-admin.setting-field
                        label="Support email"
                        name="supportEmail"
                        :default="$this->fallback('supportEmail')"
                        affects="Public. Printed in the Terms of Service and in the Contributor Agreement, which both read this one value — so it is only typed here."
                        warning="This address is published on the legal pages. It will be scraped.">
                        <input type="email" wire:model="supportEmail" autocomplete="off"
                               class="w-full rounded-lg border-0 bg-raised px-3 py-2.5 text-[0.84rem] focus:outline-none focus:ring-1 focus:ring-brand" />
                    </x-admin.setting-field>

                    <x-admin.setting-field
                        label="Admin email"
                        name="adminEmail"
                        :default="$this->fallback('adminEmail')"
                        :pending="$this->pending('adminEmail')"
                        affects="Internal, and never shown on the site. It is where a failed backup, a new error group or a blocked address will be reported."
                        warning="Nothing reads this yet: the alerts are not built, and MAIL_MAILER is still set to log so nothing would leave the server anyway. Saving it now means the alerts have somewhere to go the day they exist — but until then, this box changes nothing.">
                        <input type="email" wire:model="adminEmail" autocomplete="off"
                               class="w-full rounded-lg border-0 bg-raised px-3 py-2.5 text-[0.84rem] focus:outline-none focus:ring-1 focus:ring-brand" />
                    </x-admin.setting-field>

                    <x-admin.setting-field
                        label="Timezone"
                        name="timezone"
                        :default="$this->fallback('timezone')"
                        affects="Where the day starts for everything you read in the panel: downloads today, this week's chart, the hour the backup runs."
                        warning="Changing it redefines the day boundary. Figures already counted are not recalculated, so a month split across two zones will not add up — worth setting once, now, rather than later.">
                        <select wire:model.live="timezone"
                                class="w-full rounded-lg border-0 bg-raised px-3 py-2.5 text-[0.84rem] focus:outline-none focus:ring-1 focus:ring-brand">
                            <option value="">Use the default — {{ $this->fallback('timezone') }}</option>

                            @foreach ($this->zones as $region => $list)
                                <optgroup label="{{ $region }}" wire:key="tz-{{ $region }}">
                                    @foreach ($list as $zone)
                                        <option value="{{ $zone }}">{{ str_replace('_', ' ', $zone) }}</option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>

                        @if ($this->localTime)
                            <p class="mt-2 text-[0.8rem] text-paper/45">
                                <x-icon name="clock" style="solid" class="mr-1 text-[0.72rem] text-info" />
                                It is <span class="text-paper/75">{{ $this->localTime }}</span> there.
                            </p>
                        @endif
                    </x-admin.setting-field>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-hairline px-5 py-4">
                    <p class="text-[0.78rem] text-paper/30">
                        Every change is written to the activity log with what it was before.
                    </p>

                    <button type="submit" wire:loading.attr="disabled" wire:target="save"
                            class="shrink-0 rounded-lg bg-brand px-5 py-2.5 text-[0.82rem] text-white transition hover:brightness-110 disabled:opacity-50">
                        <span wire:loading.remove wire:target="save">Save changes</span>
                        <span wire:loading wire:target="save">Saving…</span>
                    </button>
                </div>
            </div>
        </form>

        {{-- ══════════════════════════════════════════════════════════════
             WHAT IS NOT HERE

             Three fields from the original plan, and why each one is more
             dangerous in a database than in a file. Written down because in
             six months the obvious question is "where did site_url go?".
             ══════════════════════════════════════════════════════════════ --}}
        <div class="rounded-2xl border border-hairline bg-panel">
            <div class="border-b border-hairline px-5 py-4">
                <h2 class="text-[0.95rem] font-medium">Three settings that were dropped</h2>
                <p class="mt-0.5 max-w-[74ch] text-[0.78rem] leading-relaxed text-paper/40">
                    A setting belongs in the database only if somebody who does not write code needs to change it with
                    the site running, <em>and</em> getting it wrong is recoverable. These fail the second half.
                </p>
            </div>

            <div class="divide-y divide-hairline text-[0.82rem] leading-relaxed">
                <div class="px-5 py-4">
                    <div class="font-mono text-[0.8rem] text-paper/70">site_url</div>
                    <p class="mt-1.5 max-w-[80ch] text-paper/45">
                        <span class="font-mono text-paper/60">APP_URL</span> already builds every link, signs every
                        download URL and writes the sitemap. A second copy in the database does not replace it — it
                        sits beside it and disagrees with it, and the failure is silent: password-reset links start
                        pointing somewhere else and nothing throws. Same shape of bug as the model method that took a
                        name the framework already owned.
                    </p>
                </div>

                <div class="px-5 py-4">
                    <div class="font-mono text-[0.8rem] text-paper/70">site_keywords</div>
                    <p class="mt-1.5 max-w-[80ch] text-paper/45">
                        Google stopped reading the keywords tag in 2009. The useful half of the idea — the words your
                        visitors type that the catalogue does not know — is a real feature, and it lives in Search →
                        synonyms, where a failed search is sitting next to the box that fixes it.
                    </p>
                </div>

                <div class="px-5 py-4">
                    <div class="font-mono text-[0.8rem] text-paper/70">site_author</div>
                    <p class="mt-1.5 max-w-[80ch] text-paper/45">
                        It would end up in <span class="font-mono text-paper/60">&lt;meta name="author"&gt;</span>,
                        which no search engine and no social preview reads. Authorship that matters is per sound and
                        per post, and that is already stored with the record.
                    </p>
                </div>
            </div>
        </div>
    @endif
</div>
