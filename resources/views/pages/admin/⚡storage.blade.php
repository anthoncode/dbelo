<?php

use App\Services\StorageHealth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.admin')] #[Title('Storage')] class extends Component {
    /** Progress of the missing-file scan, in rows. */
    public int $scanOffset = 0;

    public int $scanned = 0;

    public bool $scanComplete = false;

    public bool $hasScanned = false;

    /** @var array<int, array<string, mixed>> */
    public array $missing = [];

    public ?string $justChecked = null;

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    #[Computed]
    public function disks(): array
    {
        return app(StorageHealth::class)->disks();
    }

    #[Computed]
    public function usage(): array
    {
        return app(StorageHealth::class)->usage();
    }

    #[Computed]
    public function estimate(): array
    {
        return StorageHealth::monthlyEstimate($this->usage['totals']['bytes']);
    }

    #[Computed]
    public function fileCount(): int
    {
        return app(StorageHealth::class)->fileCount();
    }

    /** Run the round trip against one disk, on demand. */
    public function test(string $disk): void
    {
        app(StorageHealth::class)->check($disk);

        $this->justChecked = $disk;

        unset($this->disks);
    }

    public function testAll(): void
    {
        foreach ($this->disks as $disk) {
            // Skip what has no credentials: a guaranteed failure teaches
            // nothing and only makes the screen look worse than it is.
            if ($disk['configured']) {
                app(StorageHealth::class)->check($disk['name']);
            }
        }

        $this->justChecked = null;

        unset($this->disks);
    }

    /**
     * One slice per click.
     *
     * Checking existence is one round trip per file. Doing the whole
     * catalogue in a single request is how a diagnostics page becomes the
     * outage — so the scan advances in batches and says where it is.
     */
    public function scan(): void
    {
        $result = app(StorageHealth::class)->scanForMissing(offset: $this->scanOffset);

        $this->missing = array_merge($this->missing, $result['missing']);
        $this->scanned += $result['checked'];
        $this->scanOffset += $result['checked'];
        $this->scanComplete = $result['complete'];
        $this->hasScanned = true;
    }

    public function resetScan(): void
    {
        $this->reset(['scanOffset', 'scanned', 'scanComplete', 'hasScanned', 'missing']);
    }
}; ?>

<div class="space-y-5">

    {{-- ══════════════════════════════════════════════════════════════
         DISKS

         The active disk first, then everything that is merely ready.
         ══════════════════════════════════════════════════════════════ --}}
    <div class="rounded-2xl border border-hairline bg-panel">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-hairline px-5 py-4">
            <div>
                <h2 class="text-[0.95rem] font-medium">Disks</h2>
                <p class="mt-0.5 text-[0.78rem] text-paper/40">
                    A green light means a file was written, read back and deleted just now — not that the keys are filled in.
                </p>
            </div>

            <button type="button" wire:click="testAll" wire:loading.attr="disabled"
                    class="flex shrink-0 items-center gap-2 rounded-lg bg-raised px-3.5 py-2 text-[0.8rem] transition hover:bg-brand hover:text-white disabled:opacity-50">
                <x-icon name="rotate" style="solid" class="text-[0.75rem]" wire:loading.class="fa-spin" wire:target="testAll" />
                Test all
            </button>
        </div>

        <div class="divide-y divide-hairline">
            @foreach ($this->disks as $disk)
                @php
                    $state = $disk['check']['state'];

                    // Three states, not two. "Not configured" is a job for a
                    // person; "configured but failing" is a fault. Collapsing
                    // them into one red dot hides which of the two it is.
                    [$dot, $word] = match (true) {
                        ! $disk['configured'] => ['bg-paper/20', 'Not configured'],
                        $state === 'ok' => ['bg-success', 'Reachable'],
                        $state === 'fail' => ['bg-danger', 'Failing'],
                        default => ['bg-warning', 'Not tested'],
                    };
                @endphp

                <div class="px-5 py-4" wire:key="disk-{{ $disk['name'] }}">
                    <div class="flex flex-wrap items-start gap-4">

                        <span class="mt-1.5 size-2 shrink-0 rounded-full {{ $dot }}"></span>

                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-[0.92rem]">{{ $disk['label'] }}</span>

                                <code class="rounded bg-raised px-1.5 py-0.5 text-[0.72rem] text-paper/50">{{ $disk['name'] }}</code>

                                @if ($disk['isDefault'])
                                    <span class="rounded-full bg-brand/15 px-2 py-0.5 text-[0.68rem] font-semibold uppercase tracking-[0.1em] text-brand">
                                        Active
                                    </span>
                                @endif
                            </div>

                            <p class="mt-1 text-[0.78rem] text-paper/40">{{ $disk['note'] }}</p>

                            @if ($disk['endpoint'])
                                <p class="mt-1.5 truncate text-[0.75rem] text-paper/30" title="{{ $disk['endpoint'] }}">
                                    {{ $disk['endpoint'] }}{{ $disk['bucket'] ? ' · '.$disk['bucket'] : '' }}
                                </p>
                            @endif

                            {{-- The provider's own words. This is the single
                                 most useful thing on the screen when something
                                 is wrong, so it is not hidden behind a tooltip. --}}
                            @if ($disk['check']['error'])
                                <div class="mt-2.5 flex items-start gap-2 rounded-lg bg-danger/10 px-3 py-2">
                                    <x-icon name="triangle-exclamation" style="solid" class="mt-0.5 shrink-0 text-[0.75rem] text-danger" />
                                    <span class="min-w-0 break-words text-[0.78rem] text-paper/70">{{ $disk['check']['error'] }}</span>
                                </div>
                            @endif
                        </div>

                        <div class="flex shrink-0 items-center gap-3">
                            <div class="text-right">
                                <div @class([
                                    'text-[0.78rem]',
                                    'text-success' => $state === 'ok' && $disk['configured'],
                                    'text-danger' => $state === 'fail' && $disk['configured'],
                                    'text-paper/40' => ! $disk['configured'] || $state === 'unknown',
                                ])>{{ $word }}</div>

                                @if ($disk['check']['ms'] !== null && $disk['configured'])
                                    <div class="text-[0.72rem] tabular-nums text-paper/30">{{ $disk['check']['ms'] }} ms</div>
                                @endif
                            </div>

                            <button type="button" wire:click="test('{{ $disk['name'] }}')"
                                    wire:loading.attr="disabled" wire:target="test('{{ $disk['name'] }}')"
                                    @disabled(! $disk['configured'])
                                    @class([
                                        'rounded-lg px-3 py-1.5 text-[0.78rem] transition',
                                        'bg-raised hover:bg-brand hover:text-white' => $disk['configured'],
                                        'cursor-not-allowed bg-raised/50 text-paper/25' => ! $disk['configured'],
                                    ])>
                                <span wire:loading.remove wire:target="test('{{ $disk['name'] }}')">Test</span>
                                <span wire:loading wire:target="test('{{ $disk['name'] }}')">Testing…</span>
                            </button>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="border-t border-hairline px-5 py-3.5">
            <p class="text-[0.78rem] leading-relaxed text-paper/40">
                <x-icon name="circle-info" style="solid" class="mr-1 text-[0.72rem] text-info" />
                Filling in credentials does not move anything. The active disk is
                <code class="rounded bg-raised px-1.5 py-0.5 text-[0.72rem] text-paper/60">FILESYSTEM_DISK</code>
                in <code class="rounded bg-raised px-1.5 py-0.5 text-[0.72rem] text-paper/60">.env</code>, and every file
                remembers its own disk in the <code class="rounded bg-raised px-1.5 py-0.5 text-[0.72rem] text-paper/60">disk</code>
                column — so new uploads can go to a new provider while the old ones stay where they are.
            </p>
        </div>
    </div>

    <div class="grid gap-5 xl:grid-cols-[1.3fr_1fr]">

        {{-- ══════════════════════════════════════════════════════════════
             WHAT IS STORED
             ══════════════════════════════════════════════════════════════ --}}
        <div class="rounded-2xl border border-hairline bg-panel">
            <div class="flex items-center justify-between border-b border-hairline px-5 py-4">
                <h2 class="text-[0.95rem] font-medium">What is stored</h2>
                <span class="text-[0.78rem] tabular-nums text-paper/40">
                    {{ number_format($this->usage['totals']['files']) }} files ·
                    {{ App\Services\StorageHealth::bytesForHumans($this->usage['totals']['bytes']) }}
                </span>
            </div>

            @if ($this->usage['rows'])
                <table class="w-full text-[0.84rem]">
                    <thead>
                        <tr class="border-b border-hairline text-[0.72rem] uppercase tracking-[0.12em] text-paper/35">
                            <th class="px-5 py-2.5 text-left font-medium">Disk</th>
                            <th class="px-5 py-2.5 text-left font-medium">Kind</th>
                            <th class="px-5 py-2.5 text-right font-medium">Files</th>
                            <th class="px-5 py-2.5 text-right font-medium">Size</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-hairline">
                        @foreach ($this->usage['rows'] as $row)
                            <tr wire:key="usage-{{ $row['disk'] }}-{{ $row['purpose'] }}">
                                <td class="px-5 py-2.5">
                                    <code class="rounded bg-raised px-1.5 py-0.5 text-[0.72rem] text-paper/60">{{ $row['disk'] }}</code>
                                </td>
                                <td class="px-5 py-2.5 text-paper/70">{{ $row['purpose'] }}</td>
                                <td class="px-5 py-2.5 text-right tabular-nums text-paper/70">{{ number_format($row['files']) }}</td>
                                <td class="px-5 py-2.5 text-right tabular-nums text-paper/70">
                                    {{ App\Services\StorageHealth::bytesForHumans($row['bytes']) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <p class="px-5 py-10 text-center text-[0.88rem] text-paper/40">No audio files registered yet</p>
            @endif
        </div>

        {{-- ══════════════════════════════════════════════════════════════
             WHAT IT WOULD COST

             The fear that keeps audio on the web server is the bill. For a
             catalogue this size the bill is smaller than the fear, and the
             only way to say that convincingly is with this month's number.
             ══════════════════════════════════════════════════════════════ --}}
        <div class="rounded-2xl border border-hairline bg-panel">
            <div class="border-b border-hairline px-5 py-4">
                <h2 class="text-[0.95rem] font-medium">On Cloudflare R2, today</h2>
            </div>

            <div class="px-5 py-5">
                <div class="text-[2.1rem] font-semibold leading-none tracking-[-0.03em]">
                    @if ($this->estimate['freeTier'])
                        $0<span class="ml-2 align-middle text-[0.8rem] font-normal text-paper/40">free tier</span>
                    @else
                        ${{ number_format($this->estimate['usd'], 2) }}<span class="ml-1 align-middle text-[0.8rem] font-normal text-paper/40">/mo</span>
                    @endif
                </div>

                <p class="mt-3 text-[0.82rem] leading-relaxed text-paper/50">
                    {{ number_format($this->estimate['gb'], 2) }} GB stored.
                    The first 10 GB are free, then $0.015 per GB per month.
                </p>

                <div class="mt-4 rounded-xl bg-raised px-4 py-3">
                    <p class="text-[0.8rem] leading-relaxed text-paper/60">
                        <span class="text-success">Egress is not charged.</span>
                        That is the line that matters: storing audio is cheap everywhere,
                        <em>serving</em> it is what produces a bill, and R2 does not meter it.
                    </p>
                </div>

                <p class="mt-4 text-[0.78rem] leading-relaxed text-paper/35">
                    Prices checked 31 August 2026. An estimate of storage only — it cannot
                    know your request volume, which is billed per million operations.
                </p>
            </div>
        </div>
    </div>

    {{-- ══════════════════════════════════════════════════════════════
         MISSING FILES

         A row in sound_files whose file is not on the disk is a sound that
         404s on download while looking perfectly healthy in the catalogue.
         Nothing else on the site can find these.
         ══════════════════════════════════════════════════════════════ --}}
    <div class="rounded-2xl border border-hairline bg-panel">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-hairline px-5 py-4">
            <div>
                <h2 class="text-[0.95rem] font-medium">Missing files</h2>
                <p class="mt-0.5 text-[0.78rem] text-paper/40">
                    Rows that point at a file the disk does not have. Scanned in batches — one lookup per file is one round trip.
                </p>
            </div>

            <div class="flex shrink-0 items-center gap-2">
                @if ($this->hasScanned)
                    <button type="button" wire:click="resetScan"
                            class="rounded-lg px-3 py-2 text-[0.8rem] text-paper/45 transition hover:text-paper">
                        Start over
                    </button>
                @endif

                <button type="button" wire:click="scan" wire:loading.attr="disabled" wire:target="scan"
                        @disabled($this->scanComplete && $this->hasScanned)
                        @class([
                            'flex items-center gap-2 rounded-lg px-3.5 py-2 text-[0.8rem] transition',
                            'bg-raised hover:bg-brand hover:text-white' => ! ($this->scanComplete && $this->hasScanned),
                            'cursor-not-allowed bg-raised/50 text-paper/25' => $this->scanComplete && $this->hasScanned,
                        ])>
                    <x-icon name="magnifying-glass" style="solid" class="text-[0.75rem]" />
                    <span wire:loading.remove wire:target="scan">
                        {{ $this->hasScanned ? ($this->scanComplete ? 'Finished' : 'Scan next 250') : 'Scan for missing files' }}
                    </span>
                    <span wire:loading wire:target="scan">Scanning…</span>
                </button>
            </div>
        </div>

        @if ($this->hasScanned)
            <div class="border-b border-hairline px-5 py-3">
                <div class="flex items-center justify-between text-[0.78rem] text-paper/45">
                    <span>{{ number_format($this->scanned) }} of {{ number_format($this->fileCount) }} checked</span>
                    <span class="{{ $this->missing ? 'text-danger' : 'text-success' }}">
                        {{ count($this->missing) }} missing
                    </span>
                </div>

                <div class="mt-2 h-1 overflow-hidden rounded-full bg-raised">
                    <div class="h-full rounded-full bg-brand transition-[width] duration-500 ease-dbelo"
                         style="width: {{ $this->fileCount > 0 ? min(100, round($this->scanned / $this->fileCount * 100)) : 100 }}%"></div>
                </div>
            </div>
        @endif

        @if ($this->missing)
            <div class="divide-y divide-hairline">
                @foreach ($this->missing as $row)
                    <div class="flex flex-wrap items-center gap-3 px-5 py-3" wire:key="missing-{{ $row['id'] }}">
                        <x-admin.icon-chip icon="file-circle-xmark" />

                        <div class="min-w-0 flex-1">
                            <div class="truncate text-[0.86rem] text-paper/75">{{ $row['title'] }}</div>
                            <div class="truncate text-[0.75rem] text-paper/35" title="{{ $row['path'] }}">
                                {{ $row['disk'] }} · {{ $row['purpose'] }} · {{ $row['path'] }}
                            </div>
                        </div>

                        {{-- Plain link to the catalogue, with no query string:
                             a filter this page cannot guarantee exists would
                             promise a search and deliver an unfiltered list. --}}
                        <a href="{{ route('admin.sounds') }}" wire:navigate
                           class="shrink-0 rounded-lg bg-raised px-3 py-1.5 text-[0.78rem] transition hover:bg-brand hover:text-white">
                            Catalogue
                        </a>
                    </div>
                @endforeach
            </div>
        @elseif ($this->hasScanned)
            <div class="px-5 py-10 text-center">
                <x-icon name="circle-check" style="solid" class="text-[22px] text-success" />
                <p class="mt-2 text-[0.88rem] text-paper/50">
                    Every file checked so far is where the database says it is.
                </p>
            </div>
        @else
            <p class="px-5 py-10 text-center text-[0.88rem] text-paper/40">
                Not scanned yet.
            </p>
        @endif
    </div>

    {{-- ══════════════════════════════════════════════════════════════
         MOVING FILES

         Deliberately a command and not a button. Moving a catalogue is a
         long job that must survive a closed laptop, and a web request is
         the one place it cannot run.
         ══════════════════════════════════════════════════════════════ --}}
    <div class="rounded-2xl border border-hairline bg-panel">
        <div class="border-b border-hairline px-5 py-4">
            <h2 class="text-[0.95rem] font-medium">Moving files between disks</h2>
        </div>

        <div class="space-y-3 px-5 py-5">
            <p class="text-[0.84rem] leading-relaxed text-paper/55">
                There is no button for this on purpose. Moving a catalogue takes as long as it takes,
                and a web request is the one place that cannot survive — it needs a terminal that can be left running.
            </p>

            <div class="rounded-xl bg-rail px-4 py-3 font-mono text-[0.78rem] leading-relaxed text-paper/70">
                <div class="text-paper/35"># See what would move, without moving it</div>
                <div>php artisan sounds:migrate-storage sounds_private r2 --dry-run</div>
                <div class="mt-2.5 text-paper/35"># Move, keeping the originals until you have checked</div>
                <div>php artisan sounds:migrate-storage sounds_private r2 --keep</div>
            </div>

            <p class="text-[0.8rem] leading-relaxed text-paper/40">
                Each file's <code class="rounded bg-raised px-1.5 py-0.5 text-[0.72rem] text-paper/60">disk</code> column is
                updated as it moves, so a half-finished run is not a broken site — it is a catalogue living on two disks,
                which is a state the application already handles.
            </p>
        </div>
    </div>
</div>
