<?php

namespace App\Listeners;

use App\Exceptions\ScheduledTaskFailure;
use App\Services\ErrorReporter;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Throwable;

/**
 * Names the scheduled task that failed.
 *
 * ── THE PROBLEM THIS EXISTS FOR ──────────────────────────────────────────
 *
 * When a scheduled task fails, Laravel reports this:
 *
 *     Scheduled command [] failed with exit code [1].
 *     vendor/laravel/framework/.../ScheduleRunCommand.php:215
 *
 * Both brackets are useless and the file is Laravel's. The empty one is the
 * worst: $event->command is the SHELL STRING of the task, and a closure or a
 * queued job has none — so every `Schedule::call()` and `Schedule::job()` in
 * the application fails under the same anonymous message, at the same vendor
 * line, with the same fingerprint. They land in ONE error group that cannot
 * say which of them broke.
 *
 * Diagnosing one of these from the screen alone was impossible. It took
 * half an hour at a terminal running each closure by hand, and that only
 * narrowed it down.
 *
 * ── WHAT LARAVEL ALREADY KNEW ────────────────────────────────────────────
 *
 * All of it. ScheduledTaskFailed carries the Event object: its name, its
 * cron expression, its exit code and the exception if there was one. Nobody
 * was listening. The information was not missing — it was being thrown away
 * one frame before the reporter saw it.
 *
 * So this listener rebuilds the error with the task's identity IN THE
 * MESSAGE, which is what the error screen displays and what ErrorReporter
 * fingerprints on. Each failing task becomes its own group, with its own
 * first-seen date and its own trend.
 *
 * ── WHY A FAILURE CAN ARRIVE WITH NO EXCEPTION ───────────────────────────
 *
 * CallbackEvent decides the exit code like this:
 *
 *     $this->exitCode = $response === false ? 1 : 0;
 *
 * A closure that RETURNS FALSE is recorded as a failure without anything
 * having thrown. That is why the log can be empty while the screen shows a
 * failure every few minutes, and it is a trap worth naming on the screen
 * rather than rediscovering: an arrow function like
 * `fn () => Cache::put(...)` returns the store's boolean, which nobody
 * intended as a status.
 */
class RecordScheduledTaskFailure
{
    public function handle(ScheduledTaskFailed $event): void
    {
        /*
         * Never throws. This runs inside the scheduler's own failure path,
         * and a listener that fails there turns one broken task into a
         * broken scheduler — with nothing recorded about either.
         */
        try {
            app(ErrorReporter::class)->report($this->describe($event));
        } catch (Throwable) {
            // ErrorReporter already falls back to the file log on its own.
        }
    }

    private function describe(ScheduledTaskFailed $event): ScheduledTaskFailure
    {
        $task = $event->task;

        /*
         * The name, in order of how much it tells a person.
         *
         * description is what ->name() set, and every closure in this
         * project sets one. command is the artisan string. The last resort
         * is the cron expression, which at least narrows it to one line of
         * routes/console.php.
         */
        $name = $task->description
            ?: $task->command
            ?: ('unnamed task at '.$task->expression);

        /*
         * Strip everything up to and including 'artisan'.
         *
         * A command task's `command` is the whole shell line:
         *
         *   '/Users/.../Herd/bin/php84' 'artisan' newsletter:digest
         *
         * Two absolute paths of noise in front of the only part that names
         * anything. Worse, those paths differ between this Mac and the
         * server, so the same failing command would fingerprint as two
         * separate groups and each would look like it had just started.
         *
         * Non-greedy up to the LAST quote before artisan, because the php
         * path is quoted too — a pattern that only looked inside one pair
         * of quotes matched nothing here.
         */
        $name = preg_replace("~^.*?'artisan'\s*~", '', $name);

        $exitCode = $task->exitCode ?? 1;

        $cause = $event->exception?->getMessage();

        /*
         * A failure with no exception is the returns-false case above. Said
         * plainly, because "failed with exit code 1" and nothing in the log
         * is exactly the situation that wastes an afternoon.
         */
        $why = filled($cause)
            ? $cause
            : 'nothing was thrown — the callback returned false, which Laravel records as exit code 1';

        return new ScheduledTaskFailure(
            sprintf('Scheduled task [%s] (%s) failed: %s', trim($name), $task->expression, $why),
            $exitCode,
            $event->exception,
        );
    }
}
