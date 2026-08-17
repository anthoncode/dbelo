<?php

use App\Models\Sound;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.site')] #[Title('Sound effect')] class extends Component {
    public Sound $sound;

    public function mount(Sound $sound): void
    {
        abort_unless($sound->isPublished(), 404);

        $this->sound = $sound->load(['files', 'category', 'license', 'tags', 'user']);

        $sound->incrementQuietly('plays_count');
    }

    #[Computed]
    public function related()
    {
        return Sound::published()
            ->with('files')
            ->where('id', '!=', $this->sound->id)
            ->when($this->sound->category_id, fn ($q) => $q->where('category_id', $this->sound->category_id))
            ->limit(5)
            ->get();
    }

    public function title(): string
    {
        return $this->sound->title;
    }
}; ?>

<div>
    <div class="mx-auto max-w-4xl">

        <nav class="mb-6 flex items-center gap-2 text-sm text-ink/50 dark:text-paper/50">
            <a href="{{ route('sounds.index') }}" wire:navigate class="hover:text-brand">Sound effects</a>
            @if ($sound->category)
                <span>/</span>
                <a href="{{ route('sounds.index', ['category' => $sound->category->slug]) }}" wire:navigate class="hover:text-brand">
                    {{ $sound->category->name }}
                </a>
            @endif
        </nav>

        {{-- The dark card on a light page is the signature of the system:
             what matters most is the darkest thing on screen. --}}
        <div class="overflow-hidden rounded-panel bg-ink text-paper shadow-soft-lg dark:bg-surface-dark">

            <div class="flex items-center justify-between border-b border-paper/10 px-7 py-5">
                <div class="flex items-center gap-3">
                    <span class="size-2 rounded-full bg-brand"></span>
                    <span class="micro !text-paper/45">
                        Ready · {{ $sound->sample_rate ? number_format($sound->sample_rate / 1000, 1).' kHz' : '—' }}
                        @if ($sound->bit_depth) / {{ $sound->bit_depth }} bit @endif
                    </span>
                </div>
                @if ($sound->is_premium)
                    <span class="rounded-full bg-brand px-3 py-1 text-[10px] font-semibold uppercase tracking-[0.09em] text-white">Pro</span>
                @endif
            </div>

            <div class="grid lg:grid-cols-[1fr_260px]">
                <div class="p-7">
                    <h1 class="text-2xl font-semibold">{{ $sound->title }}</h1>
                    <div class="micro !text-paper/45 mb-7 mt-1.5">
                        {{ $sound->category?->name ?? 'Uncategorised' }} · by {{ $sound->user->name }}
                    </div>

                    <x-waveform-player :sound="$sound" :bars="120" height="h-20" />
                </div>

                <div class="border-t border-paper/10 bg-paper/[0.04] p-6 lg:border-l lg:border-t-0">
                    <div class="micro !text-paper/45 mb-3">Technical</div>

                    @foreach ([
                        'Duration' => $sound->durationForHumans(),
                        'Sample rate' => $sound->sample_rate ? number_format($sound->sample_rate / 1000, 1).' kHz' : '—',
                        'Bit depth' => $sound->bit_depth ? $sound->bit_depth.' bit' : '—',
                        'Channels' => $sound->channels === 1 ? 'Mono' : 'Stereo',
                    ] as $label => $value)
                        <div class="flex justify-between border-b border-paper/10 py-2.5 text-[0.86rem]">
                            <span class="text-paper/45">{{ $label }}</span>
                            <span>{{ $value }}</span>
                        </div>
                    @endforeach

                    <div class="flex justify-between py-2.5 text-[0.86rem]">
                        <span class="text-paper/45">Loopable</span>
                        <span class="{{ $sound->is_loopable ? 'text-brand' : '' }}">{{ $sound->is_loopable ? 'Yes' : 'No' }}</span>
                    </div>

                    <a href="{{ route('sounds.download', $sound) }}"
                       class="mt-5 flex w-full items-center justify-center gap-2 rounded-full bg-brand px-6 py-3 font-medium text-white shadow-brand transition duration-300 ease-dbelo hover:-translate-y-0.5 hover:shadow-brand-lg">
                        <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" viewBox="0 0 24 24">
                            <path d="M12 3v12m0 0 4-4m-4 4-4-4M4 19h16"/>
                        </svg>
                        Download
                    </a>

                    <div class="micro !text-paper/45 mt-3 text-center">
                        @auth
                            @php($remaining = auth()->user()->remainingDownloadsToday())
                            {{ $remaining === null ? 'Unlimited downloads' : $remaining.' left today' }}
                        @else
                            Log in to download
                        @endauth
                    </div>
                </div>
            </div>
        </div>

        @if ($sound->description)
            <p class="mt-9 leading-relaxed text-ink/70 dark:text-paper/70">{{ $sound->description }}</p>
        @endif

        {{-- License --}}
        @if ($sound->license)
            <div class="mt-9 rounded-card bg-surface p-7 shadow-soft-md dark:bg-surface-dark">
                <div class="micro">License</div>
                <h2 class="mt-2 text-lg font-medium">{{ $sound->license->name }}</h2>
                <p class="mt-2 text-[0.92rem] leading-relaxed text-ink/60 dark:text-paper/60">
                    {{ $sound->license->summary }}
                </p>

                {{-- Icon + text, never colour alone: the palette has no green
                     or red, and nobody should need colour to read a state. --}}
                <div class="mt-5 flex flex-wrap gap-2.5">
                    @foreach ([
                        ['Commercial use', $sound->license->allows_commercial],
                        ['Credit required', $sound->license->requires_attribution],
                        ['Modifications allowed', $sound->license->allows_derivatives],
                    ] as [$label, $allowed])
                        <span class="flex items-center gap-2 rounded-full bg-paper px-4 py-2 text-[0.83rem] shadow-soft-sm dark:bg-paper/10 {{ $allowed ? '' : 'opacity-45' }}">
                            <span class="{{ $allowed ? 'text-brand' : '' }} font-semibold">{{ $allowed ? '✓' : '✕' }}</span>
                            {{ $label }}
                        </span>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Tags --}}
        @if ($sound->tags->isNotEmpty())
            <div class="mt-7 flex flex-wrap gap-2">
                @foreach ($sound->tags as $tag)
                    <a href="{{ route('sounds.index', ['q' => $tag->name]) }}" wire:navigate
                       class="rounded-full bg-surface px-4 py-2 text-sm text-ink/60 shadow-soft-sm transition duration-300 ease-dbelo hover:-translate-y-0.5 hover:shadow-soft-md dark:bg-surface-dark dark:text-paper/60">
                        {{ $tag->name }}
                    </a>
                @endforeach
            </div>
        @endif

        {{-- Related --}}
        @if ($this->related->isNotEmpty())
            <section class="mt-12">
                <div class="micro">More like this</div>
                <h2 class="mb-5 mt-2 text-xl font-medium">Related sounds</h2>

                <div class="rounded-card bg-surface p-3 shadow-soft-md dark:bg-surface-dark">
                    @foreach ($this->related as $other)
                        <div wire:key="rel-{{ $other->id }}"
                             class="flex items-center gap-4 rounded-control px-4 py-3 transition duration-350 ease-dbelo hover:bg-ink/[0.04] dark:hover:bg-paper/[0.06]">
                            <a href="{{ route('sounds.show', $other) }}" wire:navigate class="w-44 shrink-0 truncate text-[0.92rem] hover:text-brand">
                                {{ $other->title }}
                            </a>
                            <x-waveform-player :sound="$other" :bars="56" class="flex-1" />
                        </div>
                    @endforeach
                </div>
            </section>
        @endif
    </div>
</div>
