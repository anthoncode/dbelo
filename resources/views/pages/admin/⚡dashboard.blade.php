<?php

use App\Models\Download;
use App\Models\Sound;
use App\Models\User;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.admin')] #[Title('Dashboard')] class extends Component {

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    #[Computed]
    public function stats(): array
    {
        return [
            [
                'label' => 'Published',
                'value' => Sound::published()->count(),
                'icon' => 'waveform-lines',
                'delta' => Sound::published()->where('published_at', '>=', now()->subWeek())->count(),
                'deltaLabel' => 'this week',
            ],
            [
                'label' => 'In review',
                'value' => Sound::where('status', 'pending')->count(),
                'icon' => 'clipboard-check',
                'highlight' => true,
            ],
            [
                'label' => 'Downloads',
                'value' => Download::count(),
                'icon' => 'arrow-down-to-line',
                'delta' => Download::where('created_at', '>=', now()->subWeek())->count(),
                'deltaLabel' => 'this week',
            ],
            [
                'label' => 'Users',
                'value' => User::count(),
                'icon' => 'users',
                'delta' => User::where('created_at', '>=', now()->subWeek())->count(),
                'deltaLabel' => 'this week',
            ],
        ];
    }

    #[Computed]
    public function needsAttention(): array
    {
        $rows = [];

        if ($failed = Sound::whereNotNull('processing_error')->count()) {
            $rows[] = ['icon' => 'triangle-exclamation', 'text' => "{$failed} ".str('sound')->plural($failed).' failed to process', 'action' => 'Review', 'route' => 'moderate'];
        }

        if ($pending = Sound::where('status', 'pending')->count()) {
            $rows[] = ['icon' => 'clipboard-check', 'text' => "{$pending} ".str('sound')->plural($pending).' waiting for review', 'action' => 'Moderate', 'route' => 'moderate'];
        }

        if ($orphans = Sound::published()->whereNull('category_id')->count()) {
            $rows[] = ['icon' => 'folder-xmark', 'text' => "{$orphans} published ".str('sound')->plural($orphans).' without a category', 'action' => null, 'route' => null];
        }

        if (Sound::published()->whereNull('license_id')->exists()) {
            $rows[] = ['icon' => 'file-contract', 'text' => 'Some published sounds have no licence attached', 'action' => null, 'route' => null];
        }

        return $rows;
    }

    #[Computed]
    public function recent()
    {
        return Sound::with(['user', 'category'])
            ->latest()
            ->limit(8)
            ->get();
    }

    #[Computed]
    public function topSounds()
    {
        return Sound::published()
            ->where('downloads_count', '>', 0)
            ->orderByDesc('downloads_count')
            ->limit(6)
            ->get();
    }
}; ?>

<div class="space-y-5">

    {{-- Stat row --}}
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($this->stats as $stat)
            <div class="group rounded-2xl border border-hairline bg-panel p-5 transition duration-300 ease-dbelo hover:border-brand/30">
                <div class="flex items-start justify-between">
                    <span class="text-[0.72rem] uppercase tracking-[0.14em] text-paper/35">{{ $stat['label'] }}</span>
                    <span @class([
                        'grid size-9 place-items-center rounded-full transition duration-200 ease-dbelo',
                        'bg-brand/15 text-brand' => $stat['highlight'] ?? false,
                        'bg-raised text-paper/45 group-hover:bg-paper/[0.10] group-hover:text-paper/70' => ! ($stat['highlight'] ?? false),
                    ])>
                        <x-icon :name="$stat['icon']" style="solid" class="text-[13px]" />
                    </span>
                </div>

                <div class="mt-3 text-[2rem] font-semibold leading-none tracking-[-0.03em]">
                    {{ number_format($stat['value']) }}
                </div>

                @isset($stat['delta'])
                    <div class="mt-2 text-[0.78rem] text-paper/40">
                        <span class="{{ $stat['delta'] > 0 ? 'text-brand' : '' }}">+{{ $stat['delta'] }}</span>
                        {{ $stat['deltaLabel'] }}
                    </div>
                @endisset
            </div>
        @endforeach
    </div>

    <div class="grid gap-5 xl:grid-cols-[1.4fr_1fr]">

        {{-- Needs attention --}}
        <div class="rounded-2xl border border-hairline bg-panel">
            <div class="flex items-center justify-between border-b border-hairline px-5 py-4">
                <h2 class="text-[0.95rem] font-medium">Needs attention</h2>
                @if ($this->needsAttention)
                    <span class="rounded-full bg-brand/15 px-2.5 py-0.5 text-[0.7rem] font-semibold text-brand">
                        {{ count($this->needsAttention) }}
                    </span>
                @endif
            </div>

            <div class="divide-y divide-hairline">
                @forelse ($this->needsAttention as $row)
                    <div class="flex items-center gap-3 px-5 py-3.5">
                        <x-admin.icon-chip :icon="$row['icon']" />
                        <span class="min-w-0 flex-1 text-[0.88rem] text-paper/75">{{ $row['text'] }}</span>
                        @if ($row['route'])
                            <a href="{{ route($row['route']) }}" wire:navigate
                               class="shrink-0 rounded-lg bg-raised px-3 py-1.5 text-[0.78rem] transition hover:bg-brand hover:text-white">
                                {{ $row['action'] }}
                            </a>
                        @endif
                    </div>
                @empty
                    <div class="px-5 py-10 text-center">
                        <x-icon name="circle-check" style="solid" class="text-[22px] text-brand" />
                        <p class="mt-2 text-[0.88rem] text-paper/50">Nothing pending</p>
                    </div>
                @endforelse
            </div>
        </div>

        {{-- Most downloaded --}}
        <div class="rounded-2xl border border-hairline bg-panel">
            <div class="border-b border-hairline px-5 py-4">
                <h2 class="text-[0.95rem] font-medium">Most downloaded</h2>
            </div>

            <div class="divide-y divide-hairline">
                @forelse ($this->topSounds as $index => $sound)
                    <div class="flex items-center gap-3 px-5 py-3">
                        <span class="w-4 shrink-0 text-[0.75rem] tabular-nums text-paper/25">{{ $index + 1 }}</span>
                        <a href="{{ route('sounds.show', $sound) }}" target="_blank"
                           class="min-w-0 flex-1 truncate text-[0.86rem] text-paper/75 transition hover:text-brand">
                            {{ $sound->title }}
                        </a>
                        <span class="shrink-0 text-[0.78rem] tabular-nums text-paper/40">{{ number_format($sound->downloads_count) }}</span>
                    </div>
                @empty
                    <p class="px-5 py-10 text-center text-[0.88rem] text-paper/40">No downloads yet</p>
                @endforelse
            </div>
        </div>
    </div>

    {{-- Recent uploads --}}
    <div class="rounded-2xl border border-hairline bg-panel">
        <div class="flex items-center justify-between border-b border-hairline px-5 py-4">
            <h2 class="text-[0.95rem] font-medium">Recent uploads</h2>
            <a href="{{ route('moderate') }}" wire:navigate class="text-[0.8rem] text-paper/40 transition hover:text-brand">
                View all
            </a>
        </div>

        <table class="w-full text-left">
            <thead>
                <tr class="border-b border-hairline text-[0.7rem] uppercase tracking-[0.12em] text-paper/30">
                    <th class="px-5 py-2.5 font-medium">Title</th>
                    <th class="px-5 py-2.5 font-medium">Category</th>
                    <th class="px-5 py-2.5 font-medium">By</th>
                    <th class="px-5 py-2.5 font-medium">Status</th>
                    <th class="px-5 py-2.5 text-right font-medium">Added</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-hairline">
                @forelse ($this->recent as $sound)
                    <tr class="transition hover:bg-paper/[0.03]">
                        <td class="max-w-0 px-5 py-3">
                            <span class="block truncate text-[0.88rem]">{{ $sound->title }}</span>
                        </td>
                        <td class="px-5 py-3 text-[0.83rem] text-paper/45">{{ $sound->category?->name ?? '—' }}</td>
                        <td class="px-5 py-3 text-[0.83rem] text-paper/45">{{ $sound->user->name }}</td>
                        <td class="px-5 py-3">
                            @php
                                $map = [
                                    'published' => ['Live', 'bg-brand/15 text-brand'],
                                    'pending' => ['In review', 'bg-paper/10 text-paper/70'],
                                    'processing' => ['Processing', 'bg-paper/10 text-paper/50'],
                                    'draft' => ['Queued', 'bg-paper/10 text-paper/50'],
                                    'rejected' => ['Rejected', 'bg-paper/[0.06] text-paper/35'],
                                ];
                                [$label, $classes] = $map[$sound->status] ?? [$sound->status, 'bg-paper/10 text-paper/50'];
                            @endphp
                            <span class="rounded-full px-2.5 py-1 text-[0.72rem] {{ $classes }}">{{ $label }}</span>
                        </td>
                        <td class="px-5 py-3 text-right text-[0.8rem] text-paper/35">{{ $sound->created_at->diffForHumans(short: true) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-5 py-10 text-center text-[0.88rem] text-paper/40">Nothing uploaded yet</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
