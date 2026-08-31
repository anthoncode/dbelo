<?php

namespace App\Jobs;

use App\Services\QueueHealth;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Does nothing except prove the worker is alive.
 *
 * An empty jobs table is ambiguous: it means either "the worker is keeping
 * up" or "nobody is running a worker and nothing has been dispatched yet".
 * Those look identical from the database and mean opposite things.
 *
 * This job removes the ambiguity from both sides at once. When it runs, it
 * stamps the cache, so the panel can say when the worker was last confirmed
 * alive. When it does NOT run, it piles up in the jobs table alongside the
 * other heartbeats — which guarantees the "oldest waiting job" reading
 * always has something to measure, even on a quiet site with no traffic.
 *
 * Dispatched every five minutes by the scheduler; also on demand from the
 * Queue screen, which is the version you use when you want an answer now.
 */
class QueueHeartbeat implements ShouldQueue
{
    use Queueable;

    /** Never retry: a stale heartbeat is a worse lie than a missing one. */
    public int $tries = 1;

    public int $timeout = 10;

    public function handle(): void
    {
        Cache::put(QueueHealth::HEARTBEAT, now()->timestamp, now()->addDay());
    }
}
