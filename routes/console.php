<?php

use App\Console\Commands\RollupStatsCommand;
use App\Console\Commands\SendDigestCommand;
use App\Jobs\QueueHeartbeat;
use App\Models\ActivityLog;
use App\Models\AbuseSignal;
use App\Models\ErrorGroup;
use App\Models\LoginAttempt;
use App\Services\Diagnostics;
use App\Services\SecurityWatch;
use Illuminate\Support\Facades\Cache;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| Hourly, and it decides for itself whether this is its hour. The day and
| time live in the database so they can be changed from the panel, which is
| the whole point of having a panel.
|
| Locally this needs `php artisan schedule:work` — dev.sh runs it.
*/
Schedule::command(SendDigestCommand::class)
    ->hourly()
    ->withoutOverlapping();

/*
| The analytics rollup. Hourly, recomputing the last three days each time so
| a late download, or an hour when the worker was down, corrects itself.
*/
Schedule::command(RollupStatsCommand::class)
    ->hourly()
    ->withoutOverlapping();

/*
| The queue heartbeat. Not a health check that asks whether the worker is
| running — it is work, and the only proof a worker exists is that work gets
| done. Two readings come out of it, and they are opposites:
|
|   it runs      → the cache is stamped, so the panel can say when the
|                  worker was last confirmed alive
|   it does not  → the heartbeats pile up in the jobs table, which gives the
|                  "oldest waiting job" alarm something to measure even on a
|                  quiet site where nothing else was dispatched
|
| Every five minutes, so a stopped worker is visible within one STALL_MINUTES
| window rather than whenever somebody happens to upload something.
*/
Schedule::job(new QueueHeartbeat)->everyFiveMinutes();

/*
| Pruning the activity log.
|
| Retention is tiered by category — security and billing entries are kept
| for years, routine content edits for months — and the tiers live on the
| model in ActivityLog::prunable(). See app/Support/ActivityCatalog.php for
| why one number for the whole table is wrong in both directions at once.
|
| Daily and at a quiet hour: it is a DELETE over a date range, and running
| it while people are working is asking for lock contention on the one table
| every admin action writes to.
*/
Schedule::command('model:prune', ['--model' => [ActivityLog::class]])
    ->dailyAt('03:20')
    ->withoutOverlapping();

/*
| Pruning error groups.
|
| Only ever removes what somebody already dispositioned — resolved or muted —
| and only once it has been quiet for six months. An OPEN group is never
| pruned however old it is: nobody looked at it, and deleting an unexamined
| error is deciding on the operator's behalf that it did not matter.
|
| The daily counts go with it through the foreign key's cascade.
*/
Schedule::command('model:prune', ['--model' => [ErrorGroup::class]])
    ->dailyAt('03:30')
    ->withoutOverlapping();

/*
| Proof that the scheduler itself is alive.
|
| Nothing else can tell you. When schedule:work stops, the analytics rollup,
| the log pruning and the digest all simply stop happening — no exception, no
| failed job, no line in any log. A stamp every five minutes is the only
| evidence that exists, and Diagnostics reads it.
|
| A closure rather than a job: a job would need the queue worker to also be
| alive, and then a stale stamp would have two possible causes instead of one.
*/
Schedule::call(fn () => Cache::put(Diagnostics::SCHEDULER_STAMP, time(), now()->addDay()))
    ->everyFiveMinutes()
    ->name('diagnostics-heartbeat');

/*
| Security.
|
| SecurityWatch looks for shapes that are invisible one row at a time — one
| address trying many accounts, one account attacked from many addresses, one
| account downloading from several places at once. None of those can be seen
| by a per-request rate limit, because every individual request in them is
| perfectly ordinary.
|
| Hourly rather than continuously: these are 24-hour windows, so checking
| more often would find the same pattern again and again, and the grouping
| would be the only thing doing any work.
*/
/*
 * name() BEFORE withoutOverlapping(), not after.
 *
 * A closure has no name of its own, so withoutOverlapping() has nothing to
 * build a mutex key from and throws THE MOMENT THE SCHEDULE IS DEFINED —
 * not when it runs. And this file is loaded by every single artisan
 * command, so getting the order wrong here does not break the scheduler,
 * it breaks `artisan`. Every command. Including migrate.
 */
Schedule::call(fn () => app(SecurityWatch::class)->scan())
    ->name('security-scan')
    ->hourly()
    ->withoutOverlapping();

/*
| Pruning the access log and old abuse signals. Same rule as everywhere else
| in this project: only what somebody already dispositioned, and never an
| open item.
*/
Schedule::command('model:prune', ['--model' => [LoginAttempt::class, AbuseSignal::class]])
    ->dailyAt('03:40')
    ->withoutOverlapping();
