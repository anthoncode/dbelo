<?php

namespace App\Console\Commands;

use App\Services\Diagnostics;
use Illuminate\Console\Command;

/**
 * The environment check, in a terminal.
 *
 * All it does is render App\Services\Diagnostics. The checks used to live
 * in here, which meant the admin screen would have had to reimplement every
 * one of them — and two implementations of the same check disagree the
 * first time either is edited, after which neither can be trusted. One
 * definition, two surfaces.
 */
class DoctorCommand extends Command
{
    protected $signature = 'dbelo:doctor';

    protected $description = 'Check that the environment is fully wired up';

    public function handle(Diagnostics $diagnostics): int
    {
        $checks = $diagnostics->run();

        $this->newLine();
        $this->line('  <options=bold>dbelo — environment check</>');

        $group = null;

        foreach ($checks as $check) {
            if ($check['group'] !== $group) {
                $group = $check['group'];
                $this->newLine();
                $this->line("  <fg=gray>{$group}</>");
            }

            [$mark, $colour] = match ($check['status']) {
                Diagnostics::OK => ['✓', 'green'],
                Diagnostics::WARN => ['!', 'yellow'],
                default => ['✕', 'red'],
            };

            $this->line(sprintf("  <fg={$colour}>%s</> %-20s <fg=gray>%s</>", $mark, $check['label'], $check['detail']));

            // The fix is only printed for things that are wrong. Printing it
            // under every passing check is how a report becomes wallpaper.
            if ($check['status'] !== Diagnostics::OK) {
                if ($check['fix']) {
                    $this->line(sprintf('    <fg=gray>%s</>', $check['fix']));
                }

                if ($check['command']) {
                    $this->line(sprintf('    <fg=cyan>%s</>', $check['command']));
                }
            }
        }

        $summary = $diagnostics->summary($checks);

        $this->newLine();

        if ($summary['fail'] === 0 && $summary['warn'] === 0) {
            $this->info('  Everything is running.');
        } else {
            $this->line(sprintf(
                '  <fg=red>%d failing</>, <fg=yellow>%d to look at</>, <fg=green>%d fine</>',
                $summary['fail'], $summary['warn'], $summary['ok'],
            ));
        }

        $this->newLine();

        return $summary['fail'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
