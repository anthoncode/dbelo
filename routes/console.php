<?php

use App\Console\Commands\RollupStatsCommand;
use App\Console\Commands\RunBackupCommand;
use App\Console\Commands\SendDigestCommand;
use App\Jobs\QueueHeartbeat;
use App\Models\AbuseSignal;
use App\Models\ActivityLog;
use App\Models\CampaignSend;
use App\Models\ErrorDaily;
use App\Models\ErrorGroup;
use App\Models\LoginAttempt;
use App\Models\NotFound;
use App\Models\SearchDaily;
use App\Models\WebhookEvent;
use App\Services\Alerts;
use App\Services\BackupManager;
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
| Pruning the tables that fill up with records nobody will ever read.
|
| Five of them, and none holds anything a person decided or a customer paid
| for. The retention for each is on its own model, next to the reason:
|
|   NotFound      bot-scanned paths after a month; dispositioned ones after
|                 six. An OPEN 404 is never pruned at any age.
|   SearchDaily   a year of day-by-day detail. The term and its lifetime
|                 count live on `searches` and stay forever.
|   ErrorDaily    six months, matching the groups. The cascade only clears
|                 these when a whole group goes, and an open group never does.
|   WebhookEvent  settled events after ninety days — far past PayPal's
|                 three-day retry window. Pending and failed are kept.
|   CampaignSend  per-recipient rows after a year. The campaign keeps its
|                 totals.
|
| DELIBERATELY NOT HERE: downloads and searches. Both grow forever and both
| are the business rather than its exhaust — the free re-download window and
| the whole of Analytics read the first, and the second is one row per term
| no matter how many times it is typed. Deleting either is a product
| decision, not housekeeping, and it does not belong in a job that runs at
| 03:50 while nobody is watching.
|
| Written now, while every one of these tables is nearly empty. A retention
| rule added the day a table becomes a problem is a retention rule written
| under pressure, against data somebody has started to rely on.
*/
Schedule::command('model:prune', ['--model' => [
    NotFound::class,
    SearchDaily::class,
    ErrorDaily::class,
    WebhookEvent::class,
    CampaignSend::class,
]])
    ->dailyAt('03:50')
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
/*
| A BLOCK, NOT AN ARROW FUNCTION, AND THAT IS THE WHOLE POINT.
|
| This was `fn () => Cache::put(...)`, and an arrow function returns its
| expression. Cache::put() returns a boolean, so the closure returned a
| boolean — and CallbackEvent decides a scheduled task's fate like this:
|
|     $this->exitCode = $response === false ? 1 : 0;
|
| So whenever the cache store answered false, the scheduler recorded a
| FAILED TASK with nothing thrown, nothing in laravel.log, and nothing that
| reproduced when the same line was run by hand. It reported a failure every
| five minutes for three weeks under a message that named no task at all.
|
| A body with no return gives null, and null === false is false. The stamp
| still gets written; the store's opinion about the write simply stops being
| read as a verdict on the task.
|
| THE GENERAL RULE, worth knowing before writing the next one of these: a
| scheduled closure must not end on an expression whose value it does not
| mean as a status. `fn () => $service->doThing()` is a trap whenever
| doThing() can return false.
*/
Schedule::call(function () {
    Cache::put(Diagnostics::SCHEDULER_STAMP, time(), now()->addDay());
})
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
/*
 * A block for the same reason as the heartbeat above, even though this one
 * is safe today.
 *
 * scan() returns an int, and `0 === false` is false under strict comparison,
 * so a quiet hour does not currently register as a failed task. That is the
 * kind of safety that holds until somebody changes a return type — and the
 * failure it would produce is the one that just cost three weeks: a task
 * reported as broken with nothing thrown and nothing in the log.
 *
 * The return value is dropped on purpose. If the number of signals raised
 * ever needs reporting, it belongs in a log line inside scan(), not in a
 * value the scheduler will read as a verdict.
 */
Schedule::call(function () {
    app(SecurityWatch::class)->scan();
})
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

/*
| Backups.
|
| --only-db, always. The audio is deliberately not in the nightly archive:
| it is hundreds of times the size of the database, most nights none of it
| has changed, and a nightly zip of it would fill the disk it is stored on
| and stop the one backup that was working. Audio leaves through
| Admin → Backups, by hand, in selections a person chooses.
|
| Hourly, and the command decides whether this is its hour — daily, weekly,
| monthly or off, at an hour chosen in Admin → Backups. The schedule cannot
| hold that itself: it is built once when the console boots, and a setting
| changed a minute later would be ignored until the process restarted.
|
| Rotation is ours too, not spatie's. Its cleanup thins by age; what was
| asked for is a flat count — keep the newest three, the newest push the
| oldest out. Two different promises, and a strategy trying to keep both
| keeps neither.
*/
Schedule::command(RunBackupCommand::class)->hourly()->withoutOverlapping();

/*
| "Nothing has been backed up for too long."
|
| A SEPARATE CHECK, and the more important of the two. The command above
| reports its own crashes — but a command that crashes and a command that
| silently never runs look identical from the outside, and the second is the
| more common failure by a wide margin: a scheduler that stopped, a cron
| entry lost in a deploy, a queue worker nobody restarted. Only something
| that looks at the FILES can tell you that.
|
| Same principle as the Backups screen: the files are the record, never a
| table claiming what the files contain.
|
| 26 hours rather than 24, so a daily backup running a few minutes late is
| not an alarm. Alerts::backupOverdue holds a one-a-day cooldown of its own —
| this runs hourly, and twenty-four identical emails before breakfast is how
| an alert folder becomes a filter rule.
|
| ->name() BEFORE ->withoutOverlapping(). For a closure the name is required
| and the exception is thrown at DEFINITION time, which means it fires for
| every artisan command — including `migrate`. That cost hours once already.
*/
Schedule::call(function () {
    $newest = collect(app(BackupManager::class)->backups())
        ->reject(fn ($b) => $b['safety'])
        ->max(fn ($b) => $b['at']->timestamp);

    $hours = $newest
        ? (int) round((time() - $newest) / 3600)
        : 999;

    if ($hours > 26) {
        app(Alerts::class)->backupOverdue($hours);
    }
})
    ->name('backup-overdue')
    ->hourly();
