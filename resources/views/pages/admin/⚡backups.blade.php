<?php

use App\Models\ActivityLog;
use App\Models\Setting;
use App\Services\AudioExport;
use App\Services\BackupManager;
use App\Services\StorageHealth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.admin')] #[Title('Backups')] class extends Component {
    /** @var array<int, string> */
    public array $purposes = ['original'];

    public string $categoryId = '';

    public string $collectionId = '';

    public string $since = '';

    /* Schedule */
    public string $frequency = 'daily';

    public string $hour = '2';

    public string $keep = '3';

    /* Restore — the typed confirmation, and which archive it is for */
    public ?string $restoring = null;

    public string $confirmation = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $manager = app(BackupManager::class);

        $this->frequency = $manager->frequency();
        $this->hour = (string) $manager->hour();
        $this->keep = (string) $manager->keep();
    }

    /* ═══════════════════════════ The schedule ═══════════════════════════ */

    public function saveSchedule(): void
    {
        $data = $this->validate([
            'frequency' => ['required', 'in:daily,weekly,monthly,off'],
            'hour' => ['required', 'integer', 'between:0,23'],
            'keep' => ['required', 'integer', 'between:1,30'],
        ]);

        Setting::put('backup.frequency', $data['frequency'], 'backup');
        Setting::put('backup.hour', $data['hour'], 'backup');
        Setting::put('backup.keep', $data['keep'], 'backup');

        ActivityLog::record('backup.settings.changed', null,
            "Backups {$data['frequency']}, keeping {$data['keep']}",
            ['frequency' => $data['frequency'], 'hour' => $data['hour'], 'keep' => $data['keep']]);

        // Applying the new count immediately, rather than at the next run:
        // lowering it and seeing nothing happen reads as the setting not
        // having saved.
        app(BackupManager::class)->rotate();

        unset($this->backups, $this->latest);

        session()->flash('ok', 'Schedule saved.');
    }

    /** How far back the current settings actually let you go. */
    #[Computed]
    public function reach(): string
    {
        $keep = max(1, (int) $this->keep);

        return match ($this->frequency) {
            'daily' => $keep.' '.str('day')->plural($keep),
            'weekly' => $keep.' '.str('week')->plural($keep),
            'monthly' => $keep.' '.str('month')->plural($keep),
            default => 'nothing — automatic backups are off',
        };
    }

    /* ════════════════════════════ Restoring ════════════════════════════ */

    public function askRestore(string $path): void
    {
        $this->restoring = $path;
        $this->confirmation = '';
    }

    public function cancelRestore(): void
    {
        $this->reset('restoring', 'confirmation');
    }

    #[Computed]
    public function databaseName(): string
    {
        return (string) DB::connection()->getDatabaseName();
    }

    /**
     * The one operation here that destroys data.
     *
     * The typed name is not theatre. This list is a column of near-identical
     * filenames differing by a timestamp, and the cost of picking the wrong
     * row is the whole database. Typing something makes you read what you
     * are about to do, which a click never does.
     */
    public function restore(): void
    {
        if (! $this->restoring || $this->confirmation !== $this->databaseName) {
            return;
        }

        try {
            $result = app(BackupManager::class)->restore($this->restoring);

            $this->reset('restoring', 'confirmation');

            unset($this->backups, $this->latest);

            session()->flash('ok', 'Restored. A copy of what was here first was kept as '
                .basename($result['safety']).'. You may need to sign in again.');
        } catch (\Throwable $e) {
            session()->flash('bad', $e->getMessage());
        }
    }

    /** The package does the machinery; without it there is nothing to show. */
    #[Computed]
    public function installed(): bool
    {
        return class_exists(\Spatie\Backup\BackupServiceProvider::class);
    }

    /* ════════════════════════ Database backups ════════════════════════ */

    /**
     * Every archive on the backups disk, newest first.
     *
     * Read from the disk rather than from a table: the files ARE the record.
     * A database row saying a backup exists, next to a disk where it does
     * not, is the failure mode this screen exists to make impossible.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function backups(): array
    {
        return app(BackupManager::class)->backups();
    }

    #[Computed]
    public function latest(): ?array
    {
        return $this->backups[0] ?? null;
    }

    /**
     * Run the dump now.
     *
     * Synchronous, and --only-db. This database holds metadata, not media,
     * so the dump is seconds. Putting it on the queue would mean a silent
     * nothing whenever the worker is down, which for the one button whose
     * job is reassurance is the worst possible trade.
     */
    public function runNow(): void
    {
        try {
            app(BackupManager::class)->run();

            unset($this->backups, $this->latest);

            session()->flash('ok', 'Backup finished. Older copies beyond the limit were removed.');
        } catch (\Throwable $e) {
            session()->flash('bad', $e->getMessage());
        }
    }

    public function download(string $path)
    {
        abort_unless(in_array($path, array_column($this->backups, 'path'), true), 404);

        return Storage::disk('backups')->download($path);
    }

    public function forget(string $path): void
    {
        abort_unless(in_array($path, array_column($this->backups, 'path'), true), 404);

        Storage::disk('backups')->delete($path);

        unset($this->backups, $this->latest);
    }

    /* ═══════════════════════════ Audio export ═══════════════════════════ */

    #[Computed]
    public function measured(): array
    {
        return app(AudioExport::class)->measure(
            $this->purposes,
            $this->categoryId ? (int) $this->categoryId : null,
            $this->collectionId ? (int) $this->collectionId : null,
            $this->since ?: null,
        );
    }

    #[Computed]
    public function tooBig(): bool
    {
        return $this->measured['bytes'] > AudioExport::MAX_BYTES;
    }

    #[Computed]
    public function categories(): array
    {
        return Schema::hasTable('categories')
            ? DB::table('categories')->orderBy('name')->pluck('name', 'id')->all()
            : [];
    }

    #[Computed]
    public function packs(): array
    {
        return Schema::hasTable('collections')
            ? DB::table('collections')->where('is_featured', true)->orderBy('name')->pluck('name', 'id')->all()
            : [];
    }

    public function exportAudio()
    {
        if ($this->measured['files'] === 0 || $this->tooBig) {
            return null;
        }

        $purposes = $this->purposes;
        $category = $this->categoryId ? (int) $this->categoryId : null;
        $collection = $this->collectionId ? (int) $this->collectionId : null;
        $since = $this->since ?: null;

        return response()->streamDownload(
            fn () => app(AudioExport::class)->stream($purposes, $category, $collection, $since),
            AudioExport::filename(),
            ['Content-Type' => 'application/zip'],
        );
    }
}; ?>

<div class="space-y-5">

    @if (! $this->installed)
        <div class="rounded-2xl border border-warning/25 bg-warning/[0.06] px-5 py-6">
            <div class="flex items-start gap-3.5">
                <span class="grid size-10 shrink-0 place-items-center rounded-full bg-warning/15 text-warning">
                    <x-icon name="box-archive" style="solid" class="text-[15px]" />
                </span>
                <div>
                    <h2 class="text-[1.02rem] font-medium">The backup package is not installed</h2>
                    <p class="mt-1.5 max-w-[64ch] text-[0.85rem] leading-relaxed text-paper/55">
                        The dumping, compressing, retention and cleanup are not written by hand here — a backup that
                        fails silently is discovered on the day you need it, and that is the one day it is too late.
                    </p>
                    <code class="mt-3.5 inline-block rounded-lg bg-rail px-3.5 py-2 font-mono text-[0.8rem] text-paper/80">composer require spatie/laravel-backup</code>
                </div>
            </div>
        </div>
    @else

        {{-- ══════════════════════════════════════════════════════════════
             THE ONE NUMBER THAT MATTERS
             ══════════════════════════════════════════════════════════════ --}}
        @php
            $latest = $this->latest;
            $ageHours = $latest ? $latest['at']->diffInHours(now()) : null;
            $state = match (true) {
                $latest === null => 'fail',
                $ageHours > 48 => 'fail',
                $ageHours > 26 => 'warn',
                default => 'ok',
            };
        @endphp

        <div @class([
            'rounded-2xl border px-5 py-5',
            'border-danger/25 bg-danger/[0.06]' => $state === 'fail',
            'border-warning/25 bg-warning/[0.06]' => $state === 'warn',
            'border-success/25 bg-success/[0.06]' => $state === 'ok',
        ])>
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex items-start gap-3.5">
                    <span @class([
                        'grid size-10 shrink-0 place-items-center rounded-full',
                        'bg-danger/15 text-danger' => $state === 'fail',
                        'bg-warning/15 text-warning' => $state === 'warn',
                        'bg-success/15 text-success' => $state === 'ok',
                    ])>
                        <x-icon :name="$state === 'ok' ? 'shield-check' : 'triangle-exclamation'" style="solid" class="text-[15px]" />
                    </span>

                    <div>
                        <h2 class="text-[1.05rem] font-medium">
                            @if ($latest)
                                Last backup {{ $latest['at']->diffForHumans() }}
                            @else
                                No backup has ever been made
                            @endif
                        </h2>

                        <p class="mt-1 max-w-[66ch] text-[0.84rem] leading-relaxed text-paper/50">
                            {{-- The age is the whole story. A backup from three weeks
                                 ago is worse than none, because you believe you are
                                 covered and you are not. --}}
                            The database runs nightly on its own. What matters is not that backups exist but
                            <em>how old the newest one is</em> — a copy from three weeks ago is worse than no copy,
                            because you think you are covered.
                        </p>
                    </div>
                </div>

                <button type="button" wire:click="runNow" wire:loading.attr="disabled" wire:target="runNow"
                        class="flex shrink-0 items-center gap-2 rounded-lg bg-raised px-3.5 py-2 text-[0.82rem] transition hover:bg-brand hover:text-white disabled:opacity-50">
                    <x-icon name="rotate" style="solid" class="text-[0.75rem]" wire:loading.class="fa-spin" wire:target="runNow" />
                    <span wire:loading.remove wire:target="runNow">Back up now</span>
                    <span wire:loading wire:target="runNow">Dumping…</span>
                </button>
            </div>

            @if (session('ok'))
                <p class="mt-3 text-[0.84rem] text-success">{{ session('ok') }}</p>
            @endif
            @if (session('bad'))
                <p class="mt-3 break-words text-[0.84rem] text-danger">{{ session('bad') }}</p>
            @endif
        </div>

        {{-- ══════════════════════════════════════════════════════════════
             SCHEDULE
             ══════════════════════════════════════════════════════════════ --}}
        <div class="rounded-2xl border border-hairline bg-panel">
            <div class="border-b border-hairline px-5 py-4">
                <h2 class="text-[0.95rem] font-medium">When, and how many</h2>
                <p class="mt-0.5 max-w-[74ch] text-[0.78rem] leading-relaxed text-paper/40">
                    The command runs every hour and asks whether this is its hour, so a change here takes effect at
                    once instead of at the next restart.
                </p>
            </div>

            <form wire:submit="saveSchedule" class="space-y-4 px-5 py-5">
                <div class="grid gap-3 sm:grid-cols-3">
                    <div>
                        <div class="micro mb-1.5 text-paper/30">How often</div>
                        <select wire:model.live="frequency"
                                class="w-full rounded-lg border-0 bg-raised px-3 py-2.5 text-[0.84rem] focus:outline-none focus:ring-1 focus:ring-brand">
                            <option value="daily">Every day</option>
                            <option value="weekly">Every week (Monday)</option>
                            <option value="monthly">Every month (the 1st)</option>
                            <option value="off">Off</option>
                        </select>
                        @error('frequency') <p class="mt-1 text-[0.74rem] text-danger">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <div class="micro mb-1.5 text-paper/30">At what hour</div>
                        <select wire:model.live="hour" @disabled($frequency === 'off')
                                class="w-full rounded-lg border-0 bg-raised px-3 py-2.5 text-[0.84rem] focus:outline-none focus:ring-1 focus:ring-brand disabled:opacity-40">
                            @for ($h = 0; $h < 24; $h++)
                                <option value="{{ $h }}" wire:key="h-{{ $h }}">{{ sprintf('%02d:00', $h) }}</option>
                            @endfor
                        </select>
                        @error('hour') <p class="mt-1 text-[0.74rem] text-danger">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <div class="micro mb-1.5 text-paper/30">Copies kept</div>
                        <input type="number" min="1" max="30" wire:model.live="keep"
                               class="w-full rounded-lg border-0 bg-raised px-3 py-2.5 text-[0.84rem] focus:outline-none focus:ring-1 focus:ring-brand" />
                        @error('keep') <p class="mt-1 text-[0.74rem] text-danger">{{ $message }}</p> @enderror
                    </div>
                </div>

                {{-- The number that actually matters, worked out for you.
                     "Three copies" says nothing on its own; "three days" is
                     the sentence you can judge — and judging it is the point,
                     because the accident that hurts is corrupting data on a
                     Friday and noticing on Tuesday. --}}
                <div class="flex flex-wrap items-center justify-between gap-4 rounded-xl bg-rail px-4 py-3.5">
                    <div>
                        <div class="text-[0.78rem] uppercase tracking-[0.14em] text-paper/30">How far back you can go</div>
                        <div class="mt-1 text-[1.05rem] font-medium {{ $frequency === 'off' ? 'text-danger' : '' }}">
                            {{ $this->reach }}
                        </div>
                        @if ($frequency === 'daily' && (int) $keep <= 3)
                            <p class="mt-1.5 max-w-[58ch] text-[0.8rem] leading-relaxed text-paper/40">
                                Enough to undo what you did yesterday. Not enough to undo something you did on Friday
                                and noticed on Tuesday — raise the count if that ever happens.
                            </p>
                        @endif
                    </div>

                    <button type="submit"
                            class="shrink-0 rounded-lg bg-brand px-4 py-2.5 text-[0.82rem] text-white transition hover:brightness-110">
                        Save
                    </button>
                </div>
            </form>
        </div>

        {{-- ══════════════════════════════════════════════════════════════
             RESTORE — the only thing here that destroys data
             ══════════════════════════════════════════════════════════════ --}}
        @if ($restoring)
            <div class="rounded-2xl border border-danger/30 bg-danger/[0.07] px-5 py-5">
                <div class="flex items-start gap-3.5">
                    <span class="grid size-10 shrink-0 place-items-center rounded-full bg-danger/15 text-danger">
                        <x-icon name="triangle-exclamation" style="solid" class="text-[15px]" />
                    </span>

                    <div class="min-w-0 flex-1">
                        <h2 class="text-[1.02rem] font-medium">Replace the live database</h2>

                        <p class="mt-1.5 text-[0.85rem] text-paper/60">
                            with <code class="rounded bg-rail px-1.5 py-0.5 font-mono text-[0.78rem] text-paper/85">{{ basename($restoring) }}</code>
                        </p>

                        <ul class="mt-3.5 space-y-1.5 text-[0.83rem] leading-relaxed text-paper/55">
                            <li>· Everything written since that copy is <strong class="text-paper/75">gone</strong> — sounds, users, downloads, this log.</li>
                            <li>· A copy of the current database is taken first and kept out of the rotation, so this is reversible.</li>
                            <li>· Migrations run afterwards, in case the copy is older than the code.</li>
                            <li>· You will probably be signed out: the sessions table goes back too.</li>
                        </ul>

                        <div class="mt-4">
                            <label class="block text-[0.8rem] text-paper/50">
                                Type <code class="rounded bg-rail px-1.5 py-0.5 font-mono text-[0.78rem] text-paper/85">{{ $this->databaseName }}</code> to enable the button
                            </label>

                            <div class="mt-2 flex flex-wrap items-center gap-2.5">
                                <input type="text" wire:model.live="confirmation" autocomplete="off" spellcheck="false"
                                       class="w-48 rounded-lg border-0 bg-rail px-3.5 py-2.5 font-mono text-[0.84rem] focus:outline-none focus:ring-1 focus:ring-danger" />

                                <button type="button" wire:click="restore" wire:loading.attr="disabled" wire:target="restore"
                                        @disabled($confirmation !== $this->databaseName)
                                        @class([
                                            'rounded-lg px-4 py-2.5 text-[0.82rem] transition',
                                            'bg-danger text-white hover:brightness-110' => $confirmation === $this->databaseName,
                                            'cursor-not-allowed bg-raised/50 text-paper/25' => $confirmation !== $this->databaseName,
                                        ])>
                                    <span wire:loading.remove wire:target="restore">Restore now</span>
                                    <span wire:loading wire:target="restore">Restoring…</span>
                                </button>

                                <button type="button" wire:click="cancelRestore"
                                        class="rounded-lg px-3 py-2.5 text-[0.82rem] text-paper/45 transition hover:text-paper">
                                    Cancel
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        {{-- ══════════════════════════════════════════════════════════════
             THE COPIES
             ══════════════════════════════════════════════════════════════ --}}
        <div class="rounded-2xl border border-hairline bg-panel">
            <div class="border-b border-hairline px-5 py-4">
                <h2 class="text-[0.95rem] font-medium">Database copies</h2>
                <p class="mt-0.5 max-w-[72ch] text-[0.78rem] leading-relaxed text-paper/40">
                    Read from the disk, not from a table — the files <em>are</em> the record. A row claiming a backup
                    exists next to a disk where it does not is exactly the lie this screen exists to prevent.
                </p>
            </div>

            <div class="divide-y divide-hairline">
                @forelse ($this->backups as $backup)
                    <div class="flex items-center gap-3.5 px-5 py-3" wire:key="bk-{{ $backup['path'] }}">
                        <x-admin.icon-chip icon="file-zipper" tone="muted" />

                        <div class="min-w-0 flex-1">
                            <div class="truncate font-mono text-[0.82rem] text-paper/75">{{ $backup['name'] }}</div>
                            @if ($backup['safety'] ?? false)
                                <span class="mt-1 inline-block rounded-full bg-info/15 px-2 py-0.5 text-[0.64rem] font-semibold uppercase tracking-[0.1em] text-info">
                                    Taken before a restore · never rotated out
                                </span>
                            @endif

                            <div class="mt-0.5 text-[0.74rem] text-paper/35">
                                {{ App\Services\StorageHealth::bytesForHumans($backup['bytes']) }}
                                · <span title="{{ $backup['at']->toDayDateTimeString() }}">{{ $backup['at']->diffForHumans() }}</span>
                            </div>
                        </div>

                        <button type="button" wire:click="download('{{ $backup['path'] }}')"
                                class="shrink-0 rounded-lg bg-raised px-3 py-1.5 text-[0.78rem] transition hover:bg-brand hover:text-white">
                            Download
                        </button>

                        {{-- Restore opens a confirmation, it does not restore.
                             The gap between wanting to and doing it is the
                             feature. --}}
                        <button type="button" wire:click="askRestore('{{ $backup['path'] }}')"
                                class="shrink-0 rounded-lg bg-raised px-3 py-1.5 text-[0.78rem] transition hover:bg-danger hover:text-white">
                            Restore
                        </button>

                        <x-admin.icon-button icon="trash" variant="muted" label="Delete this copy"
                                             wire:click="forget('{{ $backup['path'] }}')"
                                             wire:confirm="Delete this backup? There is no undo." />
                    </div>
                @empty
                    <p class="px-5 py-12 text-center text-[0.85rem] text-paper/35">
                        Nothing yet. Press “Back up now” or wait for tonight.
                    </p>
                @endforelse
            </div>

            <div class="border-t border-hairline px-5 py-3.5">
                <p class="text-[0.78rem] leading-relaxed text-warning/80">
                    <x-icon name="triangle-exclamation" style="solid" class="mr-1 text-[0.72rem]" />
                    These copies live on the same machine as the database. That protects you from a bad query, which is
                    the most likely accident while building — and from nothing that happens to the Mac. Add
                    <code class="rounded bg-raised px-1.5 py-0.5 text-[0.72rem] text-paper/60">'r2'</code> to
                    <code class="rounded bg-raised px-1.5 py-0.5 text-[0.72rem] text-paper/60">config/backup.php</code>
                    and it becomes a real backup.
                </p>
            </div>
        </div>

        {{-- ══════════════════════════════════════════════════════════════
             AUDIO — by hand, on purpose
             ══════════════════════════════════════════════════════════════ --}}
        <div class="rounded-2xl border border-hairline bg-panel">
            <div class="border-b border-hairline px-5 py-4">
                <h2 class="text-[0.95rem] font-medium">Take the audio out</h2>
                <p class="mt-0.5 max-w-[76ch] text-[0.78rem] leading-relaxed text-paper/40">
                    Not on a schedule, and not in the nightly zip. Audio is hundreds of times the size of the database
                    and most nights none of it has changed — a nightly archive of it would fill this disk and stop the
                    one backup that was working. So it comes out here, in a selection you choose, streamed straight to
                    your browser without ever being written to the server.
                </p>
            </div>

            <div class="space-y-4 px-5 py-5">

                <div>
                    <div class="micro mb-2 text-paper/30">Which files</div>
                    <div class="space-y-2">
                        @foreach (App\Services\AudioExport::PURPOSES as $key => $label)
                            <label class="flex items-start gap-2.5 text-[0.84rem] text-paper/70" wire:key="p-{{ $key }}">
                                <input type="checkbox" wire:model.live="purposes" value="{{ $key }}"
                                       class="mt-0.5 size-4 shrink-0 rounded border-0 bg-raised text-brand focus:ring-1 focus:ring-brand" />
                                <span>{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <div class="grid gap-3 sm:grid-cols-3">
                    <div>
                        <div class="micro mb-1.5 text-paper/30">Category</div>
                        <select wire:model.live="categoryId"
                                class="w-full rounded-lg border-0 bg-raised px-3 py-2.5 text-[0.84rem] focus:outline-none focus:ring-1 focus:ring-brand">
                            <option value="">All</option>
                            @foreach ($this->categories as $id => $name)
                                <option value="{{ $id }}" wire:key="cat-{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <div class="micro mb-1.5 text-paper/30">Pack</div>
                        <select wire:model.live="collectionId"
                                class="w-full rounded-lg border-0 bg-raised px-3 py-2.5 text-[0.84rem] focus:outline-none focus:ring-1 focus:ring-brand">
                            <option value="">All</option>
                            @foreach ($this->packs as $id => $name)
                                <option value="{{ $id }}" wire:key="pk-{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <div class="micro mb-1.5 text-paper/30">Added since</div>
                        <input type="date" wire:model.live="since"
                               class="w-full rounded-lg border-0 bg-raised px-3 py-2.5 text-[0.84rem] focus:outline-none focus:ring-1 focus:ring-brand" />
                    </div>
                </div>

                {{-- Measured before anything is opened. Being told the size
                     first is what makes this a decision instead of a gamble. --}}
                <div class="flex flex-wrap items-center justify-between gap-4 rounded-xl bg-rail px-4 py-3.5">
                    <div>
                        <div class="text-[1.15rem] font-semibold tabular-nums">
                            {{ number_format($this->measured['files']) }}
                            <span class="text-[0.85rem] font-normal text-paper/40">{{ Str::plural('file', $this->measured['files']) }}</span>
                            <span class="mx-1.5 text-paper/20">·</span>
                            {{ App\Services\StorageHealth::bytesForHumans($this->measured['bytes']) }}
                        </div>

                        @if ($this->tooBig)
                            <p class="mt-1.5 max-w-[62ch] text-[0.8rem] leading-relaxed text-danger">
                                Too large for one browser download. There is no resume — one interruption and the hours
                                are gone. Narrow it by category, pack or date, or pull the bucket with rclone instead.
                            </p>
                        @elseif ($this->measured['files'] === 0)
                            <p class="mt-1.5 text-[0.8rem] text-paper/35">Nothing matches this selection.</p>
                        @endif
                    </div>

                    <button type="button" wire:click="exportAudio"
                            @disabled($this->tooBig || $this->measured['files'] === 0)
                            @class([
                                'flex shrink-0 items-center gap-2 rounded-lg px-4 py-2.5 text-[0.82rem] transition',
                                'bg-brand text-white hover:brightness-110' => ! $this->tooBig && $this->measured['files'] > 0,
                                'cursor-not-allowed bg-raised/50 text-paper/25' => $this->tooBig || $this->measured['files'] === 0,
                            ])>
                        <x-icon name="file-zipper" style="solid" class="text-[0.78rem]" />
                        Download zip
                    </button>
                </div>

                <p class="text-[0.78rem] leading-relaxed text-paper/35">
                    Masters are selected by default because previews and the MP3s are <em>derived</em> — ffmpeg rebuilds
                    them from a master. Taking all three triples the download to carry two copies of what the third can
                    regenerate.
                </p>
            </div>
        </div>

        {{-- ══════════════════════════════════════════════════════════════
             THE PART NOBODY REMEMBERS
             ══════════════════════════════════════════════════════════════ --}}
        <div class="rounded-2xl border border-hairline bg-panel px-5 py-4">
            <div class="flex items-start gap-3">
                <x-icon name="key" style="solid" class="mt-0.5 shrink-0 text-[0.85rem] text-warning" />

                <div>
                    <h2 class="text-[0.9rem] font-medium">Your APP_KEY is part of the backup</h2>
                    <p class="mt-1.5 max-w-[74ch] text-[0.82rem] leading-relaxed text-paper/50">
                        Encrypted columns — two-factor secrets today, API keys once the settings screen exists — are
                        encrypted with the key in <code class="rounded bg-raised px-1.5 py-0.5 text-[0.72rem] text-paper/60">.env</code>.
                        <strong class="text-paper/70">Restore a dump without it and those columns are unreadable forever.</strong>
                        It cannot be automated, because a secret stored next to the data it protects is not a secret:
                        put it in your password manager, today, before any of this matters.
                    </p>
                </div>
            </div>
        </div>

        {{-- Restoring is a procedure, not a button. A one-click restore that
             overwrites the live database is a footgun with no safe use. --}}
        <div class="rounded-2xl border border-hairline bg-panel">
            <div class="border-b border-hairline px-5 py-4">
                <h2 class="text-[0.95rem] font-medium">Restoring</h2>
            </div>

            <div class="space-y-3 px-5 py-5">
                <p class="max-w-[74ch] text-[0.84rem] leading-relaxed text-paper/55">
                    There is a Restore button on each copy above, behind a typed confirmation and an automatic safety
                    copy. This is the same job from a terminal — for the case the panel itself is what broke, which is
                    exactly when you will want it.
                </p>

                <div class="rounded-xl bg-rail px-4 py-3 font-mono text-[0.78rem] leading-relaxed text-paper/70">
                    <div class="text-paper/35"># 1 — unzip the archive you downloaded</div>
                    <div>unzip dbelo-2026-08-31-030000.zip</div>
                    <div class="mt-2.5 text-paper/35"># 2 — restore the dump into the database</div>
                    <div>gunzip &lt; db-dumps/mysql-dbelo.sql.gz | mysql -u root dbelo</div>
                    <div class="mt-2.5 text-paper/35"># 3 — confirm the schema is where the code expects</div>
                    <div>php artisan migrate</div>
                </div>

                <p class="text-[0.8rem] leading-relaxed text-paper/35">
                    Worth doing once now, into a scratch database, while nothing depends on it. An untested backup is a
                    belief, not a plan — and the first time you find out is always the worst time.
                </p>
            </div>
        </div>
    @endif
</div>
