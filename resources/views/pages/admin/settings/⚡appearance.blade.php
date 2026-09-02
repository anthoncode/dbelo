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
        ], [
            'values.*.regex' => 'Use a six-digit hex colour, like #a32eb7.',
        ]);

        $before = Setting::cached();
        $changed = 0;

        foreach (Appearance::FIELDS as $field => $meta) {
            if ($meta['type'] === 'image') {
                continue;
            }

            $new = strtolower(trim((string) ($this->values[$field] ?? '')));
            $old = (string) ($before[$meta['key']] ?? '');

            if ($new === strtolower(Appearance::defaultValue($field))) {
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

            $this->validate([
                "files.{$field}" => ['required', 'file', 'max:2048', 'mimes:svg,png,jpg,jpeg,webp'],
            ], [
                'files.*.mimes' => 'SVG, PNG, JPG or WEBP.',
                'files.*.max' => 'Keep it under 2 MB — this loads on every page.',
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

                                            <div class="min-w-0 flex-1 space-y-2">
                                                <input type="file" wire:model="files.{{ $field }}"
                                                       accept=".svg,.png,.jpg,.jpeg,.webp"
                                                       class="block w-full text-[0.8rem] text-paper/50 file:mr-3 file:rounded-lg file:border-0 file:bg-raised file:px-3.5 file:py-2 file:text-[0.8rem] file:text-paper/70 hover:file:bg-brand hover:file:text-white" />

                                                <div wire:loading wire:target="files.{{ $field }}" class="text-[0.78rem] text-paper/40">
                                                    Uploading…
                                                </div>

                                                @if ($this->url($field))
                                                    <button type="button" wire:click="removeImage('{{ $field }}')"
                                                            class="text-[0.78rem] text-paper/35 underline-offset-2 transition hover:text-danger hover:underline">
                                                        Remove
                                                    </button>
                                                @endif
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
