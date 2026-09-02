<?php

namespace App\Console\Commands;

use App\Services\Alerts;
use App\Services\BackupManager;
use Illuminate\Console\Command;
use Throwable;

/**
 * The nightly backup, gated on a setting.
 *
 * Scheduled hourly and decides for itself whether this is its hour. That
 * indirection is not laziness: the schedule is built once when the console
 * boots, and the frequency is a value somebody can change in the panel a
 * minute later. A schedule that reads the setting at boot would keep using
 * whatever it was at boot until the process restarted — so it would appear
 * to accept the change and then quietly ignore it, which is worse than not
 * offering the choice.
 *
 * Same arrangement the weekly digest already uses.
 */
class RunBackupCommand extends Command
{
    protected $signature = 'dbelo:backup {--force : Run now regardless of the schedule}';

    protected $description = 'Back up the database if this is its scheduled hour';

    public function handle(BackupManager $backups): int
    {
        if (! $this->option('force') && ! $backups->isDue()) {
            return self::SUCCESS;
        }

        $this->info('Backing up the database…');

        /*
         * The alert is sent from HERE rather than from BackupManager.
         *
         * This is the scheduled path — nobody is watching it, so a failure
         * here is a failure nobody learns about. The panel's own "Run now"
         * button shows its exception on screen to the person who pressed it,
         * and emailing them about something they are already reading would
         * be noise.
         *
         * Alerts::backupFailed never throws, so a mail server that is down
         * cannot turn a failed backup into a crashed scheduler that then
         * stops running everything else on the hour.
         */
        try {
            $path = $backups->run();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            app(Alerts::class)->backupFailed($e->getMessage());

            return self::FAILURE;
        }

        if (! $path) {
            $this->error('The backup command ran but produced no archive.');

            app(Alerts::class)->backupFailed('The command ran but produced no archive.');

            return self::FAILURE;
        }

        $this->line("  <fg=green>✓</> {$path}");
        $this->line(sprintf('  <fg=gray>keeping the newest %d</>', $backups->keep()));

        return self::SUCCESS;
    }
}
