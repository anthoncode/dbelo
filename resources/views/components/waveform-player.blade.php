@props([
    'sound',
    'bars' => 90,
    'height' => 'h-8',
    'thickness' => 'w-[2px]',
])

@php
    use Illuminate\Support\Facades\Storage;

    $preview = $sound->files->firstWhere('purpose', 'preview');
    $src = $preview ? Storage::disk($preview->disk)->url($preview->path) : null;

    // Stored with ~400 peaks. Lists need fewer bars, so peaks are
    // averaged down to the requested resolution.
    $peaks = $sound->waveform ?? [];

    if ($peaks && count($peaks) > $bars) {
        $chunkSize = (int) ceil(count($peaks) / $bars);
        $peaks = collect($peaks)->chunk($chunkSize)
            ->map(fn ($chunk) => round($chunk->max(), 4))
            ->values()->all();
    }

    // A 7% floor keeps silence visible instead of collapsing to nothing.
    $heights = array_map(fn ($p) => max(7, (int) round($p * 100)), $peaks);
@endphp

<div
    x-data="{
        playing: false,
        progress: 0,
        current: 0,
        duration: {{ $sound->duration_ms / 1000 }},
        audio: null,

        init() {
            this.audio = this.$refs.audio;

            this.audio.addEventListener('timeupdate', () => {
                this.current = this.audio.currentTime;
                this.progress = this.audio.duration ? this.audio.currentTime / this.audio.duration : 0;
            });

            this.audio.addEventListener('ended', () => {
                this.playing = false;
                this.progress = 0;
                this.current = 0;
            });

            // Only one sound plays at a time across the page.
            window.addEventListener('dbelo-play', (e) => {
                if (e.detail !== this.$el && this.playing) {
                    this.audio.pause();
                    this.playing = false;
                }
            });
        },

        toggle() {
            if (this.playing) {
                this.audio.pause();
                this.playing = false;
                return;
            }

            window.dispatchEvent(new CustomEvent('dbelo-play', { detail: this.$el }));
            this.audio.play();
            this.playing = true;
        },

        seek(event) {
            const rect = event.currentTarget.getBoundingClientRect();
            const ratio = (event.clientX - rect.left) / rect.width;
            if (this.audio.duration) this.audio.currentTime = ratio * this.audio.duration;
        },

        format(seconds) {
            if (! seconds || isNaN(seconds)) return '0:00';
            const m = Math.floor(seconds / 60);
            const s = Math.floor(seconds % 60).toString().padStart(2, '0');
            return `${m}:${s}`;
        },
    }"
    {{ $attributes->merge(['class' => 'flex items-center gap-4']) }}
>
    <audio x-ref="audio" src="{{ $src }}" preload="none"></audio>

    <button
        type="button"
        @click="toggle()"
        class="grid size-10 shrink-0 place-items-center rounded-full bg-brand text-white shadow-brand transition duration-300 ease-dbelo hover:scale-108"
        :aria-label="playing ? 'Pause' : 'Play'"
    >
        <svg x-show="! playing" class="size-3.5 translate-x-px" viewBox="0 0 24 24" fill="currentColor">
            <path d="M8 5v14l11-7z"/>
        </svg>
        <svg x-show="playing" x-cloak class="size-3.5" viewBox="0 0 24 24" fill="currentColor">
            <path d="M6 4h4v16H6zM14 4h4v16h-4z"/>
        </svg>
    </button>

    @if ($heights)
        <div @click="seek($event)" class="relative flex-1 cursor-pointer {{ $height }}">

            {{-- Unplayed bars: currentColor, so they adapt to light or dark cards --}}
            <div class="absolute inset-0 flex items-center justify-between">
                @foreach ($heights as $h)
                    <span class="{{ $thickness }} shrink-0 rounded-full bg-current opacity-20" style="height: {{ $h }}%"></span>
                @endforeach
            </div>

            {{-- Played bars revealed by clipping: one style write per frame
                 instead of one per bar --}}
            <div class="absolute inset-0 flex items-center justify-between"
                 :style="`clip-path: inset(0 ${100 - progress * 100}% 0 0)`">
                @foreach ($heights as $h)
                    <span class="{{ $thickness }} shrink-0 rounded-full bg-brand" style="height: {{ $h }}%"></span>
                @endforeach
            </div>
        </div>
    @else
        <div class="flex-1 text-sm opacity-40">No waveform</div>
    @endif

    <div class="micro w-24 shrink-0 text-right tabular-nums">
        <span x-text="format(current)">0:00</span> / <span x-text="format(duration)"></span>
    </div>
</div>
