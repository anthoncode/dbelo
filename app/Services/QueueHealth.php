<?php

namespace App\Services;

use App\Models\Sound;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Everything the panel needs to answer one question: is work actually
 * getting done, or is it piling up?
 *
 * The readings all come from tables Laravel already maintains — `jobs`,
 * `failed_jobs` — plus one cache key stamped by the heartbeat job. Nothing
 * here writes; it is safe to call from a sidebar render.
 */
class QueueHealth
{
    /** Set by App\Jobs\QueueHeartbeat when it runs. */
    public const HEARTBEAT = 'queue.heartbeat';

    /**
     * How long a job may sit unclaimed before we call the worker stopped.
     *
     * Generous on purpose: ProcessSoundUpload can hold a worker for 40
     * seconds on a large WAV, and a short threshold would cry wolf during a
     * normal bulk upload. Ten minutes only happens when nothing is consuming.
     */
    public const STALL_MINUTES = 10;

    /**
     * A sound whose processing never finished. Same generosity: anything
     * younger than this is probably just waiting its turn.
     */
    public const STUCK_MINUTES = 15;

    // ---------------------------------------------------------------
    // Raw readings
    // ---------------------------------------------------------------

    public function driver(): string
    {
        return (string) config('queue.default');
    }

    public function pending(): int
    {
        return Schema::hasTable('jobs') ? DB::table('jobs')->count() : 0;
    }

    /** Jobs a worker has claimed and is running right now. */
    public function running(): int
    {
        return Schema::hasTable('jobs')
            ? DB::table('jobs')->whereNotNull('reserved_at')->count()
            : 0;
    }

    public function failed(): int
    {
        return Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;
    }

    /** When the oldest unclaimed job became eligible to run. */
    public function oldestWaiting(): ?CarbonInterface
    {
        if (! Schema::hasTable('jobs')) {
            return null;
        }

        $timestamp = DB::table('jobs')->whereNull('reserved_at')->min('available_at');

        return $timestamp ? Date::createFromTimestamp($timestamp) : null;
    }

    /** When a worker last finished a heartbeat. */
    public function lastSeen(): ?CarbonInterface
    {
        $timestamp = Cache::get(self::HEARTBEAT);

        return $timestamp ? Date::createFromTimestamp((int) $timestamp) : null;
    }

    // ---------------------------------------------------------------
    // The verdict
    // ---------------------------------------------------------------

    /**
     * One of: misconfigured · stopped · running · idle · unknown.
     *
     * The distinction between "idle" and "unknown" is the whole reason the
     * heartbeat exists. An empty queue with a recent heartbeat is a healthy
     * worker with nothing to do. An empty queue with no heartbeat at all is
     * a question nobody can answer — including this class, which says so
     * rather than guessing green.
     */
    public function state(): string
    {
        // sync runs jobs inside the web request. Uploads would block the
        // browser for the length of an ffmpeg run and time out.
        if ($this->driver() === 'sync') {
            return 'misconfigured';
        }

        $oldest = $this->oldestWaiting();

        if ($oldest && $oldest->diffInMinutes(now()) >= self::STALL_MINUTES) {
            return 'stopped';
        }

        $seen = $this->lastSeen();

        if ($seen && $seen->diffInMinutes(now()) < self::STALL_MINUTES) {
            return $this->pending() > 0 ? 'running' : 'idle';
        }

        // Something is waiting and it is still young: a worker may well be
        // chewing through it right now.
        return $this->pending() > 0 ? 'running' : 'unknown';
    }

    public function isHealthy(): bool
    {
        return in_array($this->state(), ['running', 'idle'], true);
    }

    // ---------------------------------------------------------------
    // Sounds, not jobs
    // ---------------------------------------------------------------

    /**
     * Sounds that never came out the other side.
     *
     * `processing_error` is set when the job failed and reported it.
     * A null `processed_at` on an old row is the quieter case: the job was
     * never picked up at all, so nothing was ever there to fail. That second
     * one is invisible in failed_jobs, which is exactly why it needs asking
     * for separately.
     */
    public function brokenSounds()
    {
        return Sound::query()
            ->where(fn ($q) => $q
                ->whereNotNull('processing_error')
                ->orWhere(fn ($w) => $w
                    ->whereNull('processed_at')
                    ->whereNull('processing_error')
                    ->where('created_at', '<', now()->subMinutes(self::STUCK_MINUTES))));
    }

    public function brokenSoundCount(): int
    {
        return $this->brokenSounds()->count();
    }

    // ---------------------------------------------------------------
    // The badge
    // ---------------------------------------------------------------

    /**
     * How many things on this screen are waiting for a decision.
     *
     * Deliberately NOT the pending count. A queue with jobs in it is a queue
     * doing its job, and a badge that is normally non-zero is a badge people
     * stop reading — at which point it cannot warn about anything. This only
     * counts what a person has to act on: dead jobs, broken sounds, and, when
     * nothing is consuming the queue, everything stuck behind that.
     */
    public function attention(): int
    {
        $total = $this->failed() + $this->brokenSoundCount();

        if ($this->state() === 'stopped') {
            $total += $this->pending();
        }

        if ($this->state() === 'misconfigured') {
            $total += 1;
        }

        return $total;
    }

    /** The strip at the top of the screen. */
    public function summary(): array
    {
        $state = $this->state();
        $seen = $this->lastSeen();
        $oldest = $this->oldestWaiting();

        return [
            'state' => $state,
            'driver' => $this->driver(),
            'pending' => $this->pending(),
            'running' => $this->running(),
            'failed' => $this->failed(),
            'broken' => $this->brokenSoundCount(),
            'last_seen' => $seen,
            'oldest_waiting' => $oldest,
            'headline' => match ($state) {
                'misconfigured' => 'The queue is set to “sync”',
                'stopped' => 'Nothing is consuming the queue',
                'running' => 'Working',
                'idle' => 'Alive, nothing waiting',
                default => 'Cannot tell yet',
            },
            'detail' => match ($state) {
                'misconfigured' => 'Jobs run inside the web request. An upload would hold the browser for the length of an ffmpeg run and time out. Set QUEUE_CONNECTION=database in .env.',
                'stopped' => $oldest
                    ? 'The oldest job has been waiting '.$oldest->diffForHumans(null, true).'. Start a worker: php artisan queue:work'
                    : 'Start a worker: php artisan queue:work',
                'running' => $seen
                    ? 'Worker last confirmed '.$seen->diffForHumans().'.'
                    : 'Jobs are moving through.',
                'idle' => 'Worker last confirmed '.$seen?->diffForHumans().'. Nothing to do.',
                default => 'The queue is empty and no worker has checked in. Send a test job to find out.',
            },
        ];
    }
}
