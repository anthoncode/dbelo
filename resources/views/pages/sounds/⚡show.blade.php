<?php

use App\Models\Sound;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts.site')] #[Title('Sound effect')] class extends Component {
    public Sound $sound;

    #[Url(except: 'similar')] public string $tab = 'similar';

    public function mount(Sound $sound): void
    {
        abort_unless($sound->isPublished(), 404);

        $this->sound = $sound->load(['files', 'category', 'license', 'tags', 'user']);

        $sound->incrementQuietly('plays_count');

        // Opened from a recent search: that search found what it was for.
        // Separates "I have nothing" from "I have it and showed the wrong
        // thing" — two failures with very different fixes.
        app(\App\Services\SearchLogger::class)->attributeClick();

        $this->shareSeo();
    }

    /**
     * Meta tags and structured data for this sound.
     *
     * The AudioObject markup is what lets Google render a play button next
     * to the result instead of a plain blue link — the single highest
     * impact SEO detail for an audio library.
     */
    protected function shareSeo(): void
    {
        $sound = $this->sound;
        $preview = $sound->files->firstWhere('purpose', 'preview');

        $description = $sound->meta_description
            ?: Str::limit(
                $sound->description
                    ?: sprintf(
                        '%s — free %s sound effect, %s. Download in MP3 or WAV.',
                        $sound->title,
                        strtolower($sound->category?->name ?? 'audio'),
                        $sound->durationForHumans()
                    ),
                155
            );

        $jsonld = [
            '@context' => 'https://schema.org',
            '@type' => 'AudioObject',
            'name' => $sound->title,
            'description' => $description,
            'url' => route('sounds.show', $sound),
            'duration' => 'PT'.max(1, (int) round($sound->duration_ms / 1000)).'S',
            'encodingFormat' => 'audio/mpeg',
            'uploadDate' => $sound->published_at?->toDateString(),
            'genre' => $sound->category?->name,
            'keywords' => $sound->tags->pluck('name')->join(', '),
            'isAccessibleForFree' => ! $sound->is_premium,
            'creator' => [
                '@type' => 'Person',
                'name' => $sound->user->name,
            ],
        ];

        if ($preview) {
            $jsonld['contentUrl'] = Storage::disk($preview->disk)->url($preview->path);
        }

        if ($sound->license) {
            $jsonld['license'] = $sound->license->url ?: route('sounds.show', $sound);
        }

        view()->share('seo', [
            'title' => $sound->meta_title ?: $sound->title,
            'description' => $description,
            'canonical' => route('sounds.show', $sound),
            'type' => 'music.song',
            'jsonld' => $jsonld,
        ]);
    }

    // ---------------------------------------------------------------
    // What sits under the player
    // ---------------------------------------------------------------

    /**
     * The technical chips: what you get and how big it is.
     *
     * Read from sound_files rather than assumed, because which formats a
     * sound actually has depends on what came out of processing — and
     * promising a WAV that is not there is worse than not offering one.
     */
    #[Computed]
    public function specs(): array
    {
        $master = $this->sound->files->firstWhere('purpose', 'original');
        $download = $this->sound->files->firstWhere('purpose', 'download');

        $chips = [];

        if ($master) {
            $chips[] = ['music', strtoupper($master->format)];
        }

        if ($this->sound->sample_rate) {
            $chips[] = ['wave-sine', number_format($this->sound->sample_rate / 1000, 1).' kHz'];
        }

        if ($this->sound->bit_depth) {
            $chips[] = ['layer-group', $this->sound->bit_depth.'-bit'];
        }

        $chips[] = ['diagram-project', $this->sound->channels === 1 ? 'Mono' : 'Stereo'];

        if ($size = ($download?->size_bytes ?: $master?->size_bytes)) {
            $chips[] = ['hard-drive', $this->megabytes($size)];
        }

        return $chips;
    }

    protected function megabytes(int $bytes): string
    {
        return $bytes >= 1024 ** 2
            ? round($bytes / 1024 ** 2, 2).' MB'
            : round($bytes / 1024).' KB';
    }

    /**
     * More like this.
     *
     * Same category first, then anything else to fill the gap. A list of
     * three because a category is thin is a worse page than a list of eight
     * where the last few are looser matches — the visitor is browsing, not
     * being served a ranking.
     */
    #[Computed]
    public function related()
    {
        $limit = 8;

        $sameCategory = Sound::published()
            ->with(['files', 'user:id,name', 'tags:id,name', 'category:id,name'])
            ->where('id', '!=', $this->sound->id)
            ->when($this->sound->category_id, fn ($q) => $q->where('category_id', $this->sound->category_id))
            ->orderByDesc('downloads_count')
            ->limit($limit)
            ->get();

        if ($sameCategory->count() >= $limit) {
            return $sameCategory;
        }

        $filler = Sound::published()
            ->with(['files', 'user:id,name', 'tags:id,name', 'category:id,name'])
            ->whereNotIn('id', $sameCategory->pluck('id')->push($this->sound->id))
            ->orderByDesc('downloads_count')
            ->limit($limit - $sameCategory->count())
            ->get();

        return $sameCategory->concat($filler);
    }

    /** The pack this sound belongs to, if it is in a published one. */
    #[Computed]
    public function pack()
    {
        return $this->sound->collections()
            ->where('is_featured', true)
            ->where('is_public', true)
            ->first();
    }

    #[Computed]
    public function packSounds()
    {
        return $this->pack
            ? $this->pack->sounds()->published()
                ->with(['files', 'user:id,name', 'tags:id,name'])
                ->limit(20)->get()
            : collect();
    }

    public function title(): string
    {
        return $this->sound->title;
    }
}; ?>

<div class="mx-auto max-w-4xl">

    <nav class="mb-6 flex items-center gap-2 text-sm text-ink/50 dark:text-paper/50">
        <a href="{{ route('sounds.index') }}" wire:navigate class="transition hover:text-brand">Sound effects</a>
        @if ($sound->category)
            <x-icon name="chevron-right" style="solid" class="text-[0.6rem] opacity-50" />
            <a href="{{ route('sounds.index', ['category' => $sound->category->slug]) }}" wire:navigate class="transition hover:text-brand">
                {{ $sound->category->name }}
            </a>
        @endif
    </nav>

    {{-- ══════════════════════════════════════════════════════════════
         THE CARD

         Dark on a light page, which is the signature of the system: the
         thing that matters most is the darkest thing on screen. It is also
         where the waveform belongs — a light background washes it out.
         ══════════════════════════════════════════════════════════════ --}}
    <div class="overflow-hidden rounded-panel bg-ink text-paper shadow-soft-lg dark:bg-surface-dark">

        <div class="p-6 sm:p-8">

            {{-- Player: big play button, the full waveform, the duration --}}
            <div class="flex items-center gap-5">
                <div class="min-w-0 flex-1">
                    <span class="micro !text-paper/40">
                        {{ $sound->type === 'music' ? 'Music' : 'Sound effect' }}
                    </span>

                    <h1 class="mt-1 text-[clamp(1.5rem,3.4vw,2.1rem)] font-semibold">{{ $sound->title }}</h1>

                    <div class="mt-1.5 text-[0.88rem] text-paper/45">
                        By <span class="text-brand">{{ $sound->user->name }}</span>
                    </div>
                </div>

                @if ($sound->is_premium)
                    <span class="shrink-0 self-start rounded-full bg-brand px-3 py-1 text-[10px] font-semibold uppercase tracking-[0.09em] text-white">Pro</span>
                @endif
            </div>

            <x-waveform-player :sound="$sound" :bars="130" height="h-16" button="size-14" class="mt-7" />

            {{-- Description, clamped. The reference's "See more" is the right
                 call: a paragraph pushing the download button below the fold
                 costs more than the paragraph is worth. --}}
            @if ($sound->description)
                <div x-data="{ open: false }" class="mt-7">
                    <div class="micro !text-paper/40 mb-2">Item details</div>
                    <p class="text-[0.95rem] leading-relaxed text-paper/70" :class="! open && 'line-clamp-2'">
                        {{ $sound->description }}
                    </p>
                    <button x-on:click="open = ! open"
                            class="mt-1.5 text-[0.85rem] text-brand underline underline-offset-4 transition hover:text-paper"
                            x-text="open ? 'See less' : 'See more'">See more</button>
                </div>
            @endif

            {{-- Specs --}}
            <div class="mt-7 flex flex-wrap items-center gap-x-5 gap-y-3">
                @foreach ($this->specs as [$icon, $label])
                    <span class="flex items-center gap-2 text-[0.86rem] text-paper/65">
                        <x-icon :name="$icon" style="solid" class="text-[0.8rem] text-paper/30" />
                        {{ $label }}
                    </span>
                @endforeach

                <span class="flex items-center gap-2 text-[0.86rem] text-paper/65">
                    <x-icon name="clock" style="solid" class="text-[0.8rem] text-paper/30" />
                    {{ $sound->durationForHumans() }}
                </span>

                @if ($sound->is_loopable)
                    <span class="flex items-center gap-2 text-[0.86rem] text-success">
                        <x-icon name="repeat" style="solid" class="text-[0.8rem]" />
                        Seamless loop
                    </span>
                @endif
            </div>

            {{-- Tags and the actions, on one line where there is room --}}
            <div class="mt-7 flex flex-wrap items-center gap-3">
                @foreach ($sound->tags as $tag)
                    <a href="{{ route('sounds.index', ['q' => $tag->name]) }}" wire:navigate
                       class="rounded-full bg-paper/[0.07] px-4 py-2 text-[0.83rem] text-paper/70 transition duration-300 ease-dbelo hover:bg-paper/[0.14] hover:text-paper">
                        {{ $tag->name }}
                    </a>
                @endforeach

                <div class="ml-auto flex items-center gap-2.5">
                    <livewire:favorite-button :sound="$sound" :key="'fav-'.$sound->id" />
                    <livewire:collection-picker :sound="$sound" :key="'pick-'.$sound->id" />

                    <x-share-menu :url="route('sounds.show', $sound)" :title="$sound->title" tone="dark" />

                    <a href="{{ route('sounds.download', $sound) }}"
                       class="flex items-center gap-2.5 rounded-full bg-action px-7 py-3 font-medium text-white transition duration-300 ease-dbelo hover:-translate-y-0.5 hover:brightness-110">
                        <x-icon name="arrow-down-to-line" style="solid" class="text-[0.85rem]" />
                        Download
                    </a>
                </div>
            </div>

            <div class="micro !text-paper/35 mt-3 text-right">
                @auth
                    @php($remaining = auth()->user()->remainingDownloadsToday())
                    {{ $remaining === null ? 'Unlimited downloads' : $remaining.' left today' }}
                @else
                    Free account needed to download
                @endauth
            </div>
        </div>
    </div>

    {{-- ══════════════════════════════════════════════════════════════
         LICENSE
         ══════════════════════════════════════════════════════════════ --}}
    @if ($sound->license)
        <div class="mt-8 rounded-card bg-surface p-7 shadow-soft-md dark:bg-surface-dark">
            <div class="flex flex-wrap items-start gap-4">
                <span class="grid size-11 shrink-0 place-items-center rounded-full bg-brand/10 text-brand">
                    <x-icon name="file-contract" style="solid" class="text-[0.95rem]" />
                </span>

                <div class="min-w-0 flex-1">
                    <div class="micro">License</div>
                    <h2 class="mt-1 text-lg font-medium">{{ $sound->license->name }}</h2>
                    <p class="mt-2 text-[0.92rem] leading-relaxed text-ink/60 dark:text-paper/60">
                        {{ $sound->license->summary }}
                    </p>
                </div>
            </div>

            {{-- Icon AND text, never colour alone: a permission a colour-blind
                 visitor reads backwards is a legal problem, not a design one. --}}
            <div class="mt-5 flex flex-wrap gap-2.5">
                @foreach ([
                    ['Commercial use', $sound->license->allows_commercial],
                    ['Credit required', $sound->license->requires_attribution],
                    ['Modifications allowed', $sound->license->allows_derivatives],
                ] as [$label, $allowed])
                    <span @class([
                        'flex items-center gap-2 rounded-full px-4 py-2 text-[0.83rem] shadow-soft-sm',
                        'bg-success/10 text-success' => $allowed,
                        'bg-ink/[0.04] text-ink/40 dark:bg-paper/[0.07] dark:text-paper/40' => ! $allowed,
                    ])>
                        <x-icon :name="$allowed ? 'circle-check' : 'circle-xmark'" style="solid" class="text-[0.8rem]" />
                        {{ $label }}
                    </span>
                @endforeach
            </div>
        </div>
    @endif

    {{-- ══════════════════════════════════════════════════════════════
         MORE
         ══════════════════════════════════════════════════════════════ --}}
    @if ($this->related->isNotEmpty() || $this->packSounds->isNotEmpty())
        <section class="mt-12">

            <div class="mb-5 flex flex-wrap items-center gap-1.5 border-b border-ink/[0.08] dark:border-paper/10">
                @foreach ([
                    'similar' => ['Similar sounds', 'waveform-lines', $this->related->count()],
                    'pack' => ['In this pack', 'box-open', $this->packSounds->count()],
                ] as $key => [$label, $icon, $count])
                    @continue($count === 0)

                    <button wire:click="$set('tab', '{{ $key }}')"
                            @class([
                                'flex items-center gap-2 border-b-2 px-4 py-3 text-[0.9rem] transition duration-300 ease-dbelo -mb-px',
                                'border-brand text-brand' => $tab === $key,
                                'border-transparent text-ink/50 hover:text-ink dark:text-paper/50 dark:hover:text-paper' => $tab !== $key,
                            ])>
                        <x-icon :name="$icon" style="solid" class="text-[0.8rem]" />
                        {{ $label }}
                        <span class="text-[0.75rem] opacity-50">{{ $count }}</span>
                    </button>
                @endforeach

                @if ($this->pack)
                    <a href="{{ route('collections.show', $this->pack) }}" wire:navigate
                       class="ml-auto flex items-center gap-2 py-3 text-[0.83rem] text-ink/45 transition hover:text-brand dark:text-paper/45">
                        {{ $this->pack->name }}
                        <x-icon name="arrow-right" style="solid" class="text-[0.7rem]" />
                    </a>
                @endif
            </div>

            <div class="rounded-card bg-surface p-3 shadow-soft-md dark:bg-surface-dark">
                @foreach (($tab === 'pack' ? $this->packSounds : $this->related) as $other)
                    <x-sound-row :sound="$other" :bars="70" wire:key="{{ $tab }}-{{ $other->id }}" />
                @endforeach
            </div>
        </section>
    @endif
</div>
