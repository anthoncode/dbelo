<?php

use App\Jobs\ProcessSoundUpload;
use App\Jobs\QueueHeartbeat;
use App\Models\ActivityLog;
use App\Models\Sound;
use App\Services\QueueHealth;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Date;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.admin')] #[Title('Queue')] class extends Component {
    use WithPagination;

    #[Url(except: 'failed')] public string $tab = 'failed';

    /** Which failed job has its stack trace open. One at a time: they are long. */
    public ?string $expanded = null;

    /** Set when a test job is dispatched, so the screen can watch for it. */
    public ?int $pingedAt = null;

    /** How long to wait for the test job before calling it a failure. */
    protected const PING_TIMEOUT = 25;

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    public function updatedTab(): void
    {
        $this->resetPage();
        $this->expanded = null;
    }

    #[Computed]
    public function health(): array
    {
        return app(QueueHealth::class)->summary();
    }

    // ---------------------------------------------------------------
    // Is the worker alive? — the live test
    // ---------------------------------------------------------------

    /**
     * Dispatch a job that does nothing but stamp the cache when it runs.
     *
     * This is the difference between "the queue is empty" and "the worker is
     * alive": the first is a fact about the table, the second is a fact about
     * a process on the server, and only actually giving it work to do can
     * tell them apart.
     */
    public function ping(): void
    {
        $this->pingedAt = now()->timestamp;

        QueueHeartbeat::dispatch();
    }

    #[Computed]
    public function pingState(): ?string
    {
        if ($this->pingedAt === null) {
            return null;
        }

        $seen = app(QueueHealth::class)->lastSeen();

        if ($seen && $seen->timestamp >= $this->pingedAt) {
            return 'ok';
        }

        return now()->timestamp - $this->pingedAt > self::PING_TIMEOUT ? 'timeout' : 'waiting';
    }

    // ---------------------------------------------------------------
    // Failed jobs
    // ---------------------------------------------------------------

    #[Computed]
    public function failedJobs()
    {
        return DB::table('failed_jobs')
            ->orderByDesc('failed_at')
            ->paginate(10);
    }

    /**
     * The job class, pulled from the serialised payload.
     *
     * Only `displayName` is read. The payload also carries the serialised
     * command object, and unserialising attacker-adjacent data to render a
     * label would be a poor trade for a slightly nicer string.
     */
    public function jobName(?string $payload): string
    {
        $decoded = json_decode((string) $payload, true);

        return class_basename($decoded['displayName'] ?? 'Unknown job');
    }

    /** The first line of a stack trace is the only part worth showing in a row. */
    public function firstLine(?string $exception): string
    {
        return str((string) $exception)->before("\n")->limit(160)->toString();
    }

    /**
     * Open or close one stack trace.
     *
     * A method rather than an inline $set: the uuid then never has to be
     * interpolated into a wire:click attribute, which is one escaping
     * question fewer to get wrong.
     */
    public function toggle(string $uuid): void
    {
        $this->expanded = $this->expanded === $uuid ? null : $uuid;
    }

    public function retry(string $uuid): void
    {
        Artisan::call('queue:retry', ['id' => [$uuid]]);

        $this->refresh();

        session()->flash('ok', 'Job pushed back onto the queue. It runs when the worker picks it up.');
    }

    public function retryAll(): void
    {
        $count = app(QueueHealth::class)->failed();

        Artisan::call('queue:retry', ['id' => ['all']]);

        ActivityLog::record('queue.retried', null, "Re-queued {$count} failed job(s)");

        $this->refresh();

        session()->flash('ok', "{$count} job(s) pushed back onto the queue.");
    }

    public function forget(string $uuid): void
    {
        app('queue.failer')->forget($uuid);

        $this->refresh();
    }

    public function flush(): void
    {
        $count = app(QueueHealth::class)->failed();

        Artisan::call('queue:flush');

        ActivityLog::record('queue.flushed', null, "Deleted {$count} failed job(s)");

        $this->refresh();

        session()->flash('ok', "{$count} failed job(s) deleted.");
    }

    // ---------------------------------------------------------------
    // Waiting
    // ---------------------------------------------------------------

    #[Computed]
    public function waiting()
    {
        return DB::table('jobs')
            ->orderBy('available_at')
            ->paginate(10);
    }

    /**
     * Remove a job that is waiting.
     *
     * Refuses anything a worker has already claimed: deleting the row does
     * not stop the process that is running it, so the only thing you achieve
     * is losing the record of what it was.
     */
    public function drop(int $id): void
    {
        $job = DB::table('jobs')->where('id', $id)->first();

        if (! $job) {
            return;
        }

        if ($job->reserved_at) {
            session()->flash('error', 'That job is running right now. Deleting the row would not stop it.');

            return;
        }

        DB::table('jobs')->where('id', $id)->delete();

        $this->refresh();

        session()->flash('ok', 'Job removed from the queue.');
    }

    // ---------------------------------------------------------------
    // Sounds
    // ---------------------------------------------------------------

    #[Computed]
    public function brokenSounds()
    {
        return app(QueueHealth::class)->brokenSounds()
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->paginate(10);
    }

    /**
     * Send one sound back through processing.
     *
     * Clearing `processing_error` first matters: it is what the catalogue
     * reads to decide whether a sound is broken, and leaving it set on a row
     * that is now queued again shows the old failure next to a job that has
     * not run yet.
     */
    public function retrySound(int $id): void
    {
        $sound = Sound::findOrFail($id);

        $sound->update(['processing_error' => null]);

        ProcessSoundUpload::dispatch($sound);

        $this->refresh();

        session()->flash('ok', "“{$sound->title}” is back in the queue.");
    }

    public function retryAllSounds(): void
    {
        $sounds = app(QueueHealth::class)->brokenSounds()->get();

        foreach ($sounds as $sound) {
            $sound->update(['processing_error' => null]);
            ProcessSoundUpload::dispatch($sound);
        }

        ActivityLog::record('queue.retried_sounds', null, "Re-queued {$sounds->count()} sound(s)");

        $this->refresh();

        session()->flash('ok', "{$sounds->count()} sound(s) back in the queue.");
    }

    protected function refresh(): void
    {
        unset($this->health, $this->failedJobs, $this->waiting, $this->brokenSounds, $this->pingState);
        cache()->forget('admin.nav.counts');
    }
}; ?>

<div @if ($this->pingState === 'waiting') wire:poll.2s @endif>
    @php
        $tone = match ($this->health['state']) {
            'running', 'idle' => 'success',
            'unknown' => 'warning',
            default => 'danger',
        };
    @endphp

    @if (session('ok'))
        <div class="mb-5 flex items-center gap-3 rounded-xl border border-success/30 bg-success/10 px-4 py-3">
            <x-icon name="circle-check" style="solid" class="text-success" />
            <span class="text-[0.88rem]">{{ session('ok') }}</span>
        </div>
    @endif

    @if (session('error'))
        <div class="mb-5 flex items-center gap-3 rounded-xl border border-danger/30 bg-danger/10 px-4 py-3">
            <x-icon name="triangle-exclamation" style="solid" class="text-danger" />
            <span class="text-[0.88rem]">{{ session('error') }}</span>
        </div>
    @endif

    <div class="mb-5">
        <h1 class="text-[1.3rem] font-semibold tracking-[-0.02em]">Queue</h1>
        <p class="mt-1 max-w-2xl text-[0.83rem] leading-relaxed text-paper/35">
            Audio processing, search indexing and every email go through here. When the worker stops, none of it
            fails — it just never happens, which is much harder to notice.
        </p>
    </div>

    {{-- ══════ TABS ══════ --}}
    <div class="mb-5 flex flex-wrap gap-1.5 rounded-2xl border border-hairline bg-panel p-1.5">
        @foreach ([
            'failed' => ['Failed jobs', 'circle-xmark', $this->health['failed']],
            'sounds' => ['Broken sounds', 'file-audio', $this->health['broken']],
            'waiting' => ['Waiting', 'hourglass-half', $this->health['pending']],
        ] as $key => [$label, $icon, $count])
            <button wire:click="$set('tab', '{{ $key }}')"
                    @class([
                        'flex items-center gap-2 rounded-xl px-4 py-2.5 text-[0.84rem] transition duration-200 ease-dbelo',
                        'bg-raised text-paper' => $tab === $key,
                        'text-paper/45 hover:text-paper' => $tab !== $key,
                    ])>
                <x-icon :name="$icon" style="solid" class="text-[11px]" />
                {{ $label }}
                @if ($count)
                    <span @class([
                        'rounded-full px-1.5 py-0.5 text-[0.68rem] tabular-nums',
                        'bg-danger/20 text-danger' => $key !== 'waiting',
                        'bg-raised text-paper/40' => $key === 'waiting',
                    ])>{{ number_format($count) }}</span>
                @endif
            </button>
        @endforeach
    </div>

    <div class="grid gap-5 xl:grid-cols-[340px_1fr]">

        {{-- ══════ COLUMN 1 — HEALTH ══════ --}}
        <div class="h-fit space-y-5">
            <div class="rounded-2xl border border-hairline bg-panel">
                <div class="flex items-center gap-3 border-b border-hairline px-5 py-4">
                    <span @class([
                        'grid size-8 shrink-0 place-items-center rounded-full',
                        'bg-success/15 text-success' => $tone === 'success',
                        'bg-warning/15 text-warning' => $tone === 'warning',
                        'bg-danger/15 text-danger' => $tone === 'danger',
                    ])>
                        <x-icon :name="match ($this->health['state']) {
                            'running' => 'gear',
                            'idle' => 'circle-check',
                            'unknown' => 'circle-question',
                            default => 'triangle-exclamation',
                        }" style="solid"
                        class="text-[12px] {{ $this->health['state'] === 'running' ? 'fa-spin' : '' }}" />
                    </span>

                    <div class="min-w-0 flex-1">
                        <h2 class="text-[0.95rem] font-medium">{{ $this->health['headline'] }}</h2>
                        <p class="text-[0.73rem] text-paper/30">{{ $this->health['driver'] }} driver</p>
                    </div>
                </div>

                <div class="space-y-4 p-5">
                    <p class="text-[0.8rem] leading-relaxed text-paper/40">{{ $this->health['detail'] }}</p>

                    @if (in_array($this->health['state'], ['stopped', 'misconfigured', 'unknown'], true))
                        <div class="rounded-xl bg-raised p-4">
                            <p class="mb-2 text-[0.72rem] uppercase tracking-[0.14em] text-paper/30">Run this</p>
                            <code class="block break-all font-mono text-[0.78rem] text-paper/70">
                                @if ($this->health['state'] === 'misconfigured')
                                    QUEUE_CONNECTION=database
                                @else
                                    php artisan queue:work --tries=3
                                @endif
                            </code>
                            @if ($this->health['state'] !== 'misconfigured')
                                <p class="mt-2 text-[0.72rem] leading-relaxed text-paper/25">
                                    Locally, ./dev.sh already starts one alongside Meilisearch and Vite.
                                </p>
                            @endif
                        </div>
                    @endif

                    <div class="grid grid-cols-3 gap-2 border-t border-hairline pt-4 text-center">
                        @foreach ([
                            ['Waiting', $this->health['pending']],
                            ['Running', $this->health['running']],
                            ['Failed', $this->health['failed']],
                        ] as [$label, $value])
                            <div>
                                <div class="text-[1.3rem] font-semibold tabular-nums leading-none">{{ number_format($value) }}</div>
                                <div class="mt-1 text-[0.68rem] uppercase tracking-[0.13em] text-paper/30">{{ $label }}</div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- ══════ THE LIVE TEST ══════ --}}
            <div class="rounded-2xl border border-hairline bg-panel p-5">
                <div class="flex items-center gap-3">
                    <x-admin.icon-chip icon="satellite-dish" tone="brand" />
                    <div class="min-w-0 flex-1">
                        <div class="text-[0.88rem]">Test the worker</div>
                        <div class="text-[0.75rem] text-paper/30">Sends a job that does nothing</div>
                    </div>
                </div>

                <p class="mt-4 text-[0.75rem] leading-relaxed text-paper/30">
                    An empty queue proves nothing — it looks the same whether the worker is keeping up or was never
                    started. This gives it something to do and waits to see if it comes back.
                </p>

                @if ($this->pingState === 'waiting')
                    <div class="mt-4 flex items-center gap-2.5 rounded-lg bg-info/10 px-3.5 py-3 text-[0.8rem] text-info">
                        <x-icon name="spinner" style="solid" class="fa-spin text-[11px]" />
                        Waiting for the worker…
                    </div>
                @elseif ($this->pingState === 'ok')
                    <div class="mt-4 flex items-center gap-2.5 rounded-lg bg-success/10 px-3.5 py-3 text-[0.8rem] text-success">
                        <x-icon name="circle-check" style="solid" class="text-[11px]" />
                        Came back. The worker is running.
                    </div>
                @elseif ($this->pingState === 'timeout')
                    <div class="mt-4 rounded-lg bg-danger/10 px-3.5 py-3">
                        <div class="flex items-center gap-2.5 text-[0.8rem] text-danger">
                            <x-icon name="circle-xmark" style="solid" class="text-[11px]" />
                            No answer in 25 seconds.
                        </div>
                        <p class="mt-1.5 text-[0.73rem] leading-relaxed text-paper/35">
                            The job is sitting in the queue. Nothing is consuming it.
                        </p>
                    </div>
                @endif

                <button wire:click="ping" wire:loading.attr="disabled" wire:target="ping"
                        class="mt-4 flex w-full items-center justify-center gap-2 rounded-lg bg-action py-2.5 text-[0.85rem] font-medium text-white transition duration-200 ease-dbelo hover:brightness-110 disabled:opacity-50">
                    <x-icon name="paper-plane" style="solid" class="text-[11px]" />
                    Send a test job
                </button>
            </div>

            {{-- ══════ BULK ACTIONS, PER TAB ══════ --}}
            @if ($tab === 'failed' && $this->health['failed'])
                <div class="space-y-2.5 rounded-2xl border border-hairline bg-panel p-5">
                    <button wire:click="retryAll" wire:confirm="Push all failed jobs back onto the queue?"
                            class="flex w-full items-center justify-center gap-2 rounded-lg bg-raised py-2.5 text-[0.85rem] text-paper/70 transition duration-200 ease-dbelo hover:bg-paper/[0.10] hover:text-paper">
                        <x-icon name="rotate-right" style="solid" class="text-[11px]" />
                        Retry all {{ number_format($this->health['failed']) }}
                    </button>

                    <button wire:click="flush" wire:confirm="Delete every failed job? This cannot be undone."
                            class="flex w-full items-center justify-center gap-2 rounded-lg bg-danger/15 py-2.5 text-[0.85rem] text-danger transition duration-200 ease-dbelo hover:bg-danger/25">
                        <x-icon name="trash" style="solid" class="text-[11px]" />
                        Delete all
                    </button>

                    <p class="pt-1 text-[0.73rem] leading-relaxed text-paper/25">
                        Retry first. A job only reaches this table after failing three times, so if the cause is still
                        there it will simply come back — which is itself useful to know.
                    </p>
                </div>
            @endif

            @if ($tab === 'sounds' && $this->health['broken'])
                <div class="rounded-2xl border border-hairline bg-panel p-5">
                    <button wire:click="retryAllSounds" wire:confirm="Send every broken sound back through processing?"
                            class="flex w-full items-center justify-center gap-2 rounded-lg bg-raised py-2.5 text-[0.85rem] text-paper/70 transition duration-200 ease-dbelo hover:bg-paper/[0.10] hover:text-paper">
                        <x-icon name="rotate-right" style="solid" class="text-[11px]" />
                        Retry all {{ number_format($this->health['broken']) }}
                    </button>

                    <p class="mt-3 text-[0.73rem] leading-relaxed text-paper/25">
                        Do this after fixing the cause — ffmpeg missing, disk full, a corrupt upload. Same command as
                        <span class="font-mono text-paper/45">php artisan sounds:retry</span>.
                    </p>
                </div>
            @endif
        </div>

        {{-- ══════ COLUMN 2 — TABLE ══════ --}}
        <div class="min-w-0 rounded-2xl border border-hairline bg-panel">

            @if ($tab === 'failed')
                <div class="border-b border-hairline px-5 py-3.5">
                    <h2 class="text-[0.95rem] font-medium">
                        Failed jobs <span class="ml-1.5 text-paper/35">{{ $this->failedJobs->total() }}</span>
                    </h2>
                    <p class="mt-0.5 text-[0.75rem] text-paper/30">Gave up after three attempts</p>
                </div>

                <table class="w-full text-left">
                    <thead>
                        <tr class="border-b border-hairline text-[0.68rem] uppercase tracking-[0.13em] text-paper/30">
                            <th class="px-5 py-2.5 font-medium">Job</th>
                            <th class="w-32 px-3 py-2.5 font-medium">When</th>
                            <th class="w-[150px] px-5 py-2.5 text-right font-medium">Actions</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-hairline">
                        @forelse ($this->failedJobs as $job)
                            <tr wire:key="fail-{{ $job->uuid }}" class="transition hover:bg-paper/[0.03]">
                                <td class="min-w-0 px-5 py-3">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="text-[0.88rem]">{{ $this->jobName($job->payload) }}</span>
                                        <span class="rounded bg-raised px-1.5 py-0.5 text-[0.68rem] text-paper/35">{{ $job->queue }}</span>
                                    </div>
                                    <div class="mt-1 truncate text-[0.73rem] text-danger/70">
                                        {{ $this->firstLine($job->exception) }}
                                    </div>
                                </td>

                                <td class="px-3 py-3 text-[0.78rem] text-paper/35">
                                    {{ Date::parse($job->failed_at)->diffForHumans() }}
                                </td>

                                <td class="px-5 py-3">
                                    <div class="flex items-center justify-end gap-1">
                                        <x-admin.icon-button icon="magnifying-glass-plus" variant="muted" label="Full error"
                                                             wire:click="toggle('{{ $job->uuid }}')" />

                                        <x-admin.icon-button icon="rotate-right" variant="brand" label="Retry"
                                                             wire:click="retry('{{ $job->uuid }}')" />

                                        <x-admin.icon-button icon="trash" label="Delete"
                                                             class="hover:!bg-danger/15 hover:!text-danger"
                                                             wire:click="forget('{{ $job->uuid }}')" />
                                    </div>
                                </td>
                            </tr>

                            @if ($expanded === $job->uuid)
                                <tr wire:key="trace-{{ $job->uuid }}" class="bg-raised">
                                    <td colspan="3" class="px-5 py-4">
                                        <pre class="max-h-80 overflow-auto whitespace-pre-wrap break-all font-mono text-[0.72rem] leading-relaxed text-paper/45">{{ $job->exception }}</pre>
                                    </td>
                                </tr>
                            @endif
                        @empty
                            <tr>
                                <td colspan="3" class="px-5 py-16 text-center">
                                    <x-icon name="circle-check" style="regular" class="text-[24px] text-success/40" />
                                    <p class="mt-3 text-[0.9rem] text-paper/45">Nothing has failed</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>

                @if ($this->failedJobs->hasPages())
                    <div class="border-t border-hairline">
                        <x-admin.pagination :paginator="$this->failedJobs" prefix="fj" />
                    </div>
                @endif

            @elseif ($tab === 'sounds')
                <div class="border-b border-hairline px-5 py-3.5">
                    <h2 class="text-[0.95rem] font-medium">
                        Broken sounds <span class="ml-1.5 text-paper/35">{{ $this->brokenSounds->total() }}</span>
                    </h2>
                    <p class="mt-0.5 text-[0.75rem] text-paper/30">
                        Processing failed, or never ran at all
                    </p>
                </div>

                <table class="w-full text-left">
                    <thead>
                        <tr class="border-b border-hairline text-[0.68rem] uppercase tracking-[0.13em] text-paper/30">
                            <th class="px-5 py-2.5 font-medium">Sound</th>
                            <th class="w-40 px-3 py-2.5 font-medium">Problem</th>
                            <th class="w-[150px] px-5 py-2.5 text-right font-medium">Actions</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-hairline">
                        @forelse ($this->brokenSounds as $sound)
                            <tr wire:key="snd-{{ $sound->id }}" class="transition hover:bg-paper/[0.03]">
                                <td class="min-w-0 px-5 py-3">
                                    <div class="truncate text-[0.89rem]">{{ $sound->title }}</div>
                                    <div class="mt-0.5 text-[0.73rem] text-paper/25">
                                        {{ $sound->user?->name ?? 'No uploader' }} ·
                                        {{ $sound->created_at?->diffForHumans() }}
                                    </div>
                                    @if ($sound->processing_error)
                                        <div class="mt-1 truncate text-[0.73rem] text-danger/70">{{ $sound->processing_error }}</div>
                                    @endif
                                </td>

                                <td class="px-3 py-3">
                                    @if ($sound->processing_error)
                                        <span class="rounded-full bg-danger/15 px-2.5 py-1 text-[0.72rem] text-danger">Failed</span>
                                    @else
                                        {{-- The quiet one: no error, because the job never ran to produce one. --}}
                                        <span class="rounded-full bg-warning/15 px-2.5 py-1 text-[0.72rem] text-warning">Never ran</span>
                                    @endif
                                </td>

                                <td class="px-5 py-3">
                                    <div class="flex items-center justify-end gap-1">
                                        <a href="{{ route('sounds.show', $sound) }}" target="_blank" rel="noopener">
                                            <x-admin.icon-button icon="arrow-up-right-from-square" label="Open" />
                                        </a>

                                        <x-admin.icon-button icon="rotate-right" variant="brand" label="Process again"
                                                             wire:click="retrySound({{ $sound->id }})" />
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="px-5 py-16 text-center">
                                    <x-icon name="circle-check" style="regular" class="text-[24px] text-success/40" />
                                    <p class="mt-3 text-[0.9rem] text-paper/45">Every sound came out the other side</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>

                @if ($this->brokenSounds->hasPages())
                    <div class="border-t border-hairline">
                        <x-admin.pagination :paginator="$this->brokenSounds" prefix="bs" />
                    </div>
                @endif

            @else
                <div class="border-b border-hairline px-5 py-3.5">
                    <h2 class="text-[0.95rem] font-medium">
                        Waiting <span class="ml-1.5 text-paper/35">{{ $this->waiting->total() }}</span>
                    </h2>
                    <p class="mt-0.5 text-[0.75rem] text-paper/30">
                        Oldest first — the top row is the one to watch
                    </p>
                </div>

                <table class="w-full text-left">
                    <thead>
                        <tr class="border-b border-hairline text-[0.68rem] uppercase tracking-[0.13em] text-paper/30">
                            <th class="px-5 py-2.5 font-medium">Job</th>
                            <th class="w-32 px-3 py-2.5 font-medium">Waiting</th>
                            <th class="w-24 px-3 py-2.5 font-medium">Tries</th>
                            <th class="w-[150px] px-5 py-2.5 text-right font-medium">Actions</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-hairline">
                        @forelse ($this->waiting as $job)
                            @php $age = Date::createFromTimestamp($job->available_at); @endphp

                            <tr wire:key="job-{{ $job->id }}" class="transition hover:bg-paper/[0.03]">
                                <td class="min-w-0 px-5 py-3">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="text-[0.88rem]">{{ $this->jobName($job->payload) }}</span>
                                        @if ($job->reserved_at)
                                            <span class="flex items-center gap-1.5 rounded-full bg-info/15 px-2.5 py-0.5 text-[0.7rem] text-info">
                                                <x-icon name="gear" style="solid" class="fa-spin text-[9px]" />
                                                running
                                            </span>
                                        @endif
                                    </div>
                                    <div class="mt-0.5 text-[0.72rem] text-paper/25">{{ $job->queue }}</div>
                                </td>

                                <td class="px-3 py-3">
                                    <span @class([
                                        'text-[0.78rem]',
                                        'text-danger' => $age->diffInMinutes(now()) >= 10,
                                        'text-paper/35' => $age->diffInMinutes(now()) < 10,
                                    ])>{{ $age->diffForHumans(null, true) }}</span>
                                </td>

                                <td class="px-3 py-3 text-[0.8rem] tabular-nums text-paper/50">{{ $job->attempts }}</td>

                                <td class="px-5 py-3">
                                    <div class="flex items-center justify-end gap-1">
                                        <x-admin.icon-button icon="trash" label="Remove from queue"
                                                             class="hover:!bg-danger/15 hover:!text-danger"
                                                             wire:click="drop({{ $job->id }})" />
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-5 py-16 text-center">
                                    <x-icon name="hourglass-half" style="regular" class="text-[24px] text-paper/20" />
                                    <p class="mt-3 text-[0.9rem] text-paper/45">Nothing waiting</p>
                                    <p class="mx-auto mt-1.5 max-w-sm text-[0.8rem] leading-relaxed text-paper/25">
                                        Which on its own means nothing — use the test job to find out whether that is
                                        because the worker is keeping up.
                                    </p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>

                @if ($this->waiting->hasPages())
                    <div class="border-t border-hairline">
                        <x-admin.pagination :paginator="$this->waiting" prefix="wj" />
                    </div>
                @endif
            @endif
        </div>
    </div>
</div>
