<?php

use App\Models\ActivityLog;
use App\Models\Setting;
use App\Support\Appearance;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.admin')] #[Title('Appearance')] class extends Component {
    use WithFileUploads;

    /** @var array<string, string> */
    public array $values = [];

    /** Pending uploads, keyed by field. */
    public array $files = [];

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $stored = Setting::cached();

        foreach (Appearance::FIELDS as $field => $meta) {
            // Images are not text you edit — the stored value is a path
            // nobody types. It stays out of the form entirely; uploading
            // replaces it and Remove clears it.
            if ($meta['type'] === 'image') {
                continue;
            }

            $this->values[$field] = (string) ($stored[$meta['key']] ?? '')
                ?: Appearance::defaultValue($field);
        }
    }

    public function save(): void
    {
        $this->validate([
            // Six hex digits, and nothing else. This string is printed
            // inside a <style> block, so anything looser is a stranger in a
            // CSS context — Appearance::hex() checks it again on the way
            // out, because the value could also have arrived by some other
            // route entirely.
            'values.brand_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'values.action_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],

            // Capped because it is set over an image in a column about
            // 480px wide. Past roughly eighty characters it stops being a
            // headline and starts being a paragraph in headline type,
            // which wraps into the picture and reads badly.
            'values.login_title' => ['nullable', 'string', 'max:80'],
        ], [
            'values.*.regex' => 'Use a six-digit hex colour, like #a32eb7.',
            'values.login_title.max' => 'Keep the headline under 80 characters — it is one line over a picture.',
        ]);

        $before = Setting::cached();
        $changed = 0;

        foreach (Appearance::FIELDS as $field => $meta) {
            if ($meta['type'] === 'image') {
                continue;
            }

            $new = trim((string) ($this->values[$field] ?? ''));
            $old = (string) ($before[$meta['key']] ?? '');
            $default = Appearance::defaultValue($field);

            /*
             * Case-folding belongs to colours and to nothing else.
             *
             * #A32EB7 and #a32eb7 are one value and should not read as a
             * change, so both sides are lowered before comparing. A
             * headline is prose: lowering it would quietly turn "New in
             * dbelo" into "new in dbelo" on the way to the database, and
             * the admin would retype the capital every save and watch it
             * vanish again with nothing explaining why.
             */
            if ($meta['type'] === 'color') {
                $new = strtolower($new);
                $default = strtolower($default);
            }

            if ($new === $default) {
                $new = '';
            }

            if ($new === $old) {
                continue;
            }

            Setting::put($meta['key'], $new === '' ? null : $new, 'appearance');

            ActivityLog::record('settings.updated', null,
                'Appearance — '.$meta['label'].($new === '' ? ' reset to the default' : ' changed'),
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
     * Take an uploaded mark and put it on the media disk.
     *
     * Saved immediately rather than on the form's Save button: an upload has
     * already happened by the time you see it, and a file sitting in a
     * temporary directory waiting for a second click is a file that
     * disappears when the page is refreshed.
     */
    public function updatedFiles(): void
    {
        foreach ($this->files as $field => $file) {
            if (! $file || ! array_key_exists($field, Appearance::FIELDS)) {
                continue;
            }

            $maxKb = $this->maxKb($field);
            $maxLabel = $this->humanKb($maxKb);

            $this->validate([
                "files.{$field}" => ['required', 'file', "max:{$maxKb}", 'mimes:svg,png,jpg,jpeg,webp'],
            ], [
                'files.*.mimes' => 'SVG, PNG, JPG or WEBP.',
                'files.*.max' => $this->serverIsTheLimit($field)
                    ? "This server accepts at most {$maxLabel} per upload — that is PHP's own limit, not this screen's."
                    : ($field === 'login_image'
                        ? "Keep it under {$maxLabel}. A photo that big is worth compressing anyway — it is the first thing somebody signing in waits for."
                        : "Keep it under {$maxLabel} — this loads on every page."),
            ]);

            if (! $this->svgIsSafe($file)) {
                $this->addError("files.{$field}", 'That SVG contains a script or an event handler, so it was not saved.');
                $this->files[$field] = null;

                return;
            }

            $old = Appearance::text($field);

            $path = $file->storePublicly(Appearance::FOLDER, config('dbelo.storage.media', 'public'));

            Setting::put(Appearance::FIELDS[$field]['key'], $path, 'appearance');

            // The previous file is deleted only after the new one is stored:
            // a failed upload must not leave the site with no logo at all.
            if ($old && $old !== $path) {
                rescue(fn () => Storage::disk(config('dbelo.storage.media', 'public'))->delete($old), null, false);
            }

            ActivityLog::record('settings.updated', null,
                'Appearance — '.Appearance::FIELDS[$field]['label'].' replaced',
                ['setting' => Appearance::FIELDS[$field]['key']]);

            $this->files[$field] = null;

            session()->flash('ok', Appearance::FIELDS[$field]['label'].' updated.');
        }
    }

    public function removeImage(string $field): void
    {
        if ((Appearance::FIELDS[$field]['type'] ?? '') !== 'image') {
            return;
        }

        $path = Appearance::text($field);

        Setting::put(Appearance::FIELDS[$field]['key'], null, 'appearance');

        if ($path) {
            rescue(fn () => Storage::disk(config('dbelo.storage.media', 'public'))->delete($path), null, false);
        }

        ActivityLog::record('settings.updated', null,
            'Appearance — '.Appearance::FIELDS[$field]['label'].' removed',
            ['setting' => Appearance::FIELDS[$field]['key']]);

        session()->flash('ok', Appearance::FIELDS[$field]['label'].' removed.');
    }

    /**
     * An SVG is a document, not a picture.
     *
     * It can carry <script>, event handlers and external references, and it
     * is served from this site's own origin — so a hostile one opened
     * directly runs with the site's cookies. Only an admin can get here, and
     * an admin can already do worse; this is cheap and closes the obvious
     * hole rather than trusting that the only admin is always you.
     *
     * Not a full sanitiser. A real one is a library, and the honest position
     * is that this rejects the recognisable cases rather than claiming to
     * catch every one.
     */
    private function svgIsSafe($file): bool
    {
        if (strtolower($file->getClientOriginalExtension()) !== 'svg') {
            return true;
        }

        $contents = rescue(fn () => (string) file_get_contents($file->getRealPath()), '', false);

        return ! preg_match('/<script|javascript:|\son\w+\s*=|<foreignObject/i', $contents);
    }

    /* ═══════════════════════ How big a file may be ═══════════════════════ */

    /*
     * WHY THIS IS COMPUTED AND NOT A NUMBER.
     *
     * A file larger than PHP's own upload_max_filesize or post_max_size
     * never reaches Laravel. PHP discards the body, the validator never
     * runs, and Livewire can only report "failed to upload" — no size, no
     * setting, no way for the person staring at it to know that the file
     * was the problem rather than the uploader.
     *
     * So this screen refuses to state a limit it cannot keep. It asks the
     * server what it will actually accept and shows the smaller of that and
     * what the field wants, which turns an unexplained failure into a
     * number somebody can read before choosing a file.
     */

    /** "8M", "512K", "2G" → bytes. 0 means unset or unlimited. */
    private static function iniBytes(string $key): int
    {
        $raw = strtolower(trim((string) ini_get($key)));

        if ($raw === '' || $raw === '0' || $raw === '-1') {
            return 0;
        }

        $n = (int) $raw;

        return match (substr($raw, -1)) {
            'g' => $n * 1024 * 1024 * 1024,
            'm' => $n * 1024 * 1024,
            'k' => $n * 1024,
            default => $n,
        };
    }

    /** What the field would like to allow, before the server has a say. */
    public function wantedKb(string $field): int
    {
        // A logo is a small flat file that loads on every page, so 2 MB is
        // already generous. The login picture is a photograph, it loads on
        // two pages, and it is the one field where a straight-off-the-camera
        // JPEG is the normal thing to upload.
        return $field === 'login_image' ? 4096 : 2048;
    }

    /** The largest upload this server will accept at all, in KB. */
    public function serverCeilingKb(): int
    {
        /*
         * Livewire's own cap on a temporary upload when no
         * config/livewire.php has been published. It does not bind at 2 and
         * 4 MB, but it would the moment a field asked for more — and being
         * stopped by an invisible framework default is exactly the failure
         * this method exists to prevent.
         */
        $ceiling = 12288;

        $limits = array_filter([
            self::iniBytes('upload_max_filesize'),
            self::iniBytes('post_max_size'),
        ]);

        return $limits === [] ? $ceiling : min($ceiling, intdiv(min($limits), 1024));
    }

    public function maxKb(string $field): int
    {
        return min($this->wantedKb($field), $this->serverCeilingKb());
    }

    /** True when the server, and not this screen, is what decides. */
    public function serverIsTheLimit(string $field): bool
    {
        return $this->serverCeilingKb() < $this->wantedKb($field);
    }

    public function humanKb(int $kb): string
    {
        return $kb >= 1024
            ? rtrim(rtrim(number_format($kb / 1024, 1), '0'), '.').' MB'
            : $kb.' KB';
    }

    /* ═════════════════════════════ Helpers ═════════════════════════════ */

    #[Computed]
    public function ready(): bool
    {
        return Schema::hasTable('settings');
    }

    public function url(string $field): ?string
    {
        return Appearance::url($field);
    }

    public function fallback(string $field): string
    {
        return (string) config('dbelo.'.Appearance::FIELDS[$field]['key']);
    }
}; ?>

<div class="space-y-5">

    @if (! $this->ready)
        <x-admin.migration-pending what="Appearance settings" table="settings" />
    @else

        @if (session('ok'))
            <div class="rounded-2xl border border-success/25 bg-success/[0.06] px-5 py-3.5 text-[0.86rem] text-paper/75">
                <x-icon name="circle-check" style="solid" class="mr-1.5 text-[0.8rem] text-success" />
                {{ session('ok') }}
            </div>
        @endif

        {{-- ══════════════════════════════════════════════════════════════
             THE ONE THAT IS ALREADY BROKEN
             ══════════════════════════════════════════════════════════════ --}}
        @unless ($this->url('social_image'))
            <div class="rounded-2xl border border-warning/25 bg-warning/[0.06] px-5 py-4">
                <div class="flex items-start gap-3.5">
                    <x-icon name="image-slash" style="solid" class="mt-[0.2rem] shrink-0 text-[0.9rem] text-warning" />
                    <div class="min-w-0">
                        <div class="text-[0.9rem] text-paper/80">Shared links have no preview image.</div>
                        <p class="mt-1 max-w-[78ch] text-[0.82rem] leading-relaxed text-paper/50">
                            Every page points <span class="font-mono text-paper/70">og:image</span> at
                            <span class="font-mono text-paper/70">/og-default.png</span>, and that file is not in
                            <span class="font-mono text-paper/70">public/</span>. So a link pasted into WhatsApp or Slack
                            today shows a blank box where the picture should be. Upload one below and it is fixed
                            everywhere at once.
                        </p>
                    </div>
                </div>
            </div>
        @endunless

        <form wire:submit="save"
              x-data="{ dirty: false }"
              x-on:input="dirty = true"
              x-on:change="dirty = true"
              x-on:saved.window="dirty = false"
              class="space-y-5 pb-24">

            @foreach (\App\Support\Appearance::SECTIONS as $sectionKey => $section)
                <div class="rounded-2xl border border-hairline bg-panel" wire:key="sec-{{ $sectionKey }}">
                    <div class="border-b border-hairline px-5 py-4">
                        <h2 class="text-[0.95rem] font-medium">{{ $section['label'] }}</h2>
                        @if ($section['note'])
                            <p class="mt-0.5 max-w-[76ch] text-[0.78rem] leading-relaxed text-paper/40">{{ $section['note'] }}</p>
                        @endif
                    </div>

                    <div class="divide-y divide-hairline">
                        @foreach ($section['fields'] as $field)
                            @php $meta = \App\Support\Appearance::FIELDS[$field]; @endphp

                            <div wire:key="f-{{ $field }}">
                                <x-admin.setting-field
                                    :label="$meta['label']"
                                    :name="$meta['type'] === 'image' ? 'files.'.$field : 'values.'.$field"
                                    :affects="$meta['help'] ?: null">

                                    @if ($meta['type'] === 'image')
                                        <div class="flex flex-wrap items-center gap-4">
                                            {{-- The checkerboard is not decoration:
                                                 a logo is usually transparent, and
                                                 on a flat dark panel you cannot see
                                                 whether it has a white box behind
                                                 it until it is on the live site. --}}
                                            <div class="grid size-20 shrink-0 place-items-center overflow-hidden rounded-xl border border-hairline"
                                                 style="background-image:
                                                     linear-gradient(45deg, rgba(255,255,255,.06) 25%, transparent 25%),
                                                     linear-gradient(-45deg, rgba(255,255,255,.06) 25%, transparent 25%),
                                                     linear-gradient(45deg, transparent 75%, rgba(255,255,255,.06) 75%),
                                                     linear-gradient(-45deg, transparent 75%, rgba(255,255,255,.06) 75%);
                                                     background-size: 12px 12px;
                                                     background-position: 0 0, 0 6px, 6px -6px, -6px 0;">
                                                @if ($this->url($field))
                                                    <img src="{{ $this->url($field) }}" alt="" class="max-h-16 max-w-16 object-contain" />
                                                @else
                                                    <x-icon name="image" style="regular" class="text-[18px] text-paper/20" />
                                                @endif
                                            </div>

                                            <div class="min-w-0 flex-1 space-y-2"
                                                 x-data="{ failed: false }"
                                                 x-on:livewire-upload-start="failed = false"
                                                 x-on:livewire-upload-error="failed = true">

                                                <input type="file" wire:model="files.{{ $field }}"
                                                       accept=".svg,.png,.jpg,.jpeg,.webp"
                                                       class="block w-full text-[0.8rem] text-paper/50 file:mr-3 file:rounded-lg file:border-0 file:bg-raised file:px-3.5 file:py-2 file:text-[0.8rem] file:text-paper/70 hover:file:bg-brand hover:file:text-white" />

                                                <div wire:loading wire:target="files.{{ $field }}" class="text-[0.78rem] text-paper/40">
                                                    Uploading…
                                                </div>

                                                {{-- Stated before anything is chosen. A file over PHP's
                                                     own limit is discarded by the server before Laravel
                                                     sees it, so the validation message below can never
                                                     fire for that case — this line is the only warning
                                                     that arrives in time to be useful. --}}
                                                <p class="text-[0.74rem] {{ $this->serverIsTheLimit($field) ? 'text-warning' : 'text-paper/30' }}">
                                                    @if ($this->serverIsTheLimit($field))
                                                        Up to {{ $this->humanKb($this->maxKb($field)) }} — this is your
                                                        <span class="font-mono">php.ini</span> limit, not this screen's.
                                                    @else
                                                        Up to {{ $this->humanKb($this->maxKb($field)) }}.
                                                    @endif
                                                </p>

                                                {{-- The failure Livewire cannot describe.

                                                     When PHP rejects the request body there is no
                                                     validation error to show, only "failed to upload" —
                                                     which says that something went wrong and nothing
                                                     about what. This replaces it with the cause and the
                                                     name of the setting to change. --}}
                                                <p x-show="failed" style="display: none"
                                                   class="rounded-lg border border-danger/25 bg-danger/[0.06] px-3 py-2 text-[0.78rem] leading-relaxed text-danger">
                                                    The server refused the file before Laravel saw it, so nothing was
                                                    validated. Almost always the file is over PHP's
                                                    <span class="font-mono">upload_max_filesize</span> or
                                                    <span class="font-mono">post_max_size</span>. This server currently
                                                    accepts <strong>{{ $this->humanKb($this->serverCeilingKb()) }}</strong> per upload.
                                                </p>

                                                @if ($this->url($field))
                                                    <button type="button" wire:click="removeImage('{{ $field }}')"
                                                            class="text-[0.78rem] text-paper/35 underline-offset-2 transition hover:text-danger hover:underline">
                                                        Remove
                                                    </button>
                                                @endif
                                            </div>
                                        </div>

                                    @elseif ($meta['type'] === 'text')
                                        {{-- The counter is not decoration. maxlength
                                             alone just stops accepting keystrokes at
                                             the limit, which feels like a broken
                                             keyboard; the count turns that into a
                                             rule somebody can see coming. It turns
                                             amber over 70 for the same reason. --}}
                                        <div x-data="{ n: {{ strlen((string) ($this->values[$field] ?? '')) }} }" class="space-y-1.5">
                                            <input type="text"
                                                   wire:model="values.{{ $field }}"
                                                   x-on:input="n = $event.target.value.length"
                                                   maxlength="80"
                                                   placeholder="New this week on dbelo"
                                                   class="w-full max-w-[46ch] rounded-lg border-0 bg-raised px-3 py-2.5 text-[0.86rem] focus:outline-none focus:ring-1 focus:ring-brand" />

                                            <div class="text-[0.74rem] tabular-nums"
                                                 :class="n > 70 ? 'text-warning' : 'text-paper/30'">
                                                <span x-text="n"></span>/80
                                            </div>
                                        </div>

                                    @else
                                        {{-- The picker and the hex box are one
                                             control bound to one value, not two
                                             fields that can disagree. --}}
                                        <div class="flex items-center gap-3">
                                            <input type="color" wire:model.live="values.{{ $field }}"
                                                   class="size-11 shrink-0 cursor-pointer rounded-lg border-0 bg-raised p-1" />

                                            <input type="text" wire:model.live.debounce.400ms="values.{{ $field }}"
                                                   maxlength="7" spellcheck="false"
                                                   class="w-36 rounded-lg border-0 bg-raised px-3 py-2.5 font-mono text-[0.84rem] uppercase focus:outline-none focus:ring-1 focus:ring-brand" />

                                            <span class="text-[0.78rem] text-paper/30">
                                                Built in: <span class="font-mono">{{ $this->fallback($field) }}</span>
                                            </span>
                                        </div>
                                    @endif
                                </x-admin.setting-field>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach

            {{-- ══════════════════════════════════════════════════════════
                 WHAT IS NOT HERE
                 ══════════════════════════════════════════════════════════ --}}
            <div class="rounded-2xl border border-hairline bg-panel">
                <div class="border-b border-hairline px-5 py-4">
                    <h2 class="text-[0.95rem] font-medium">Three things this screen deliberately does not do</h2>
                </div>

                <div class="divide-y divide-hairline text-[0.82rem] leading-relaxed">
                    <div class="px-5 py-4">
                        <div class="text-paper/70">Repaint the status colours</div>
                        <p class="mt-1.5 max-w-[80ch] text-paper/45">
                            Red, amber, green and blue carry meaning rather than taste — red means something failed,
                            in the panel and on the site alike. Making them adjustable would turn every status colour
                            in the product into a lie, and the first person misled would be whoever changed them.
                        </p>
                    </div>

                    <div class="px-5 py-4">
                        <div class="text-paper/70">Change the typeface</div>
                        <p class="mt-1.5 max-w-[80ch] text-paper/45">
                            Fonts are fetched at build time, so a font chosen here could only be loaded from a third
                            party on every page view — slower, and it hands a stranger the IP address of every
                            visitor. The site uses Outfit, with Playfair Display for the one italic accent word.
                        </p>
                    </div>

                    <div class="px-5 py-4">
                        <div class="text-paper/70">Set a default light or dark theme</div>
                        <p class="mt-1.5 max-w-[80ch] text-paper/45">
                            The theme class is applied before first paint by <span class="font-mono">@fluxAppearance</span>,
                            which is what stops the white flash on every load. A second mechanism setting the same
                            class would be two things fighting over it, and the flash would come back — for a
                            preference each visitor already controls with the toggle in the header.
                        </p>
                    </div>
                </div>
            </div>

            <x-admin.save-bar />
        </form>
    @endif
</div>
