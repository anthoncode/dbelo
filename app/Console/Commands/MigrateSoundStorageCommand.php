<?php

namespace App\Console\Commands;

use App\Models\SoundFile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Moves stored audio from one disk to another and repoints the database.
 *
 *   php artisan sounds:migrate-storage sounds_private r2 --dry-run
 *   php artisan sounds:migrate-storage sounds_private r2
 *   php artisan sounds:migrate-storage public r2 --purpose=preview
 *
 * This is what the `disk` column on sound_files was for: the move is a data
 * change, not a code change.
 */
class MigrateSoundStorageCommand extends Command
{
    protected $signature = 'sounds:migrate-storage
                            {from : Disk the files are on now}
                            {to : Disk to move them to}
                            {--purpose= : Only original, preview or download}
                            {--dry-run : Report what would move, change nothing}
                            {--keep : Do not delete the source file after copying}';

    protected $description = 'Move audio files between storage disks';

    public function handle(): int
    {
        $from = $this->argument('from');
        $to = $this->argument('to');
        $dry = $this->option('dry-run');

        foreach ([$from, $to] as $disk) {
            if (! config("filesystems.disks.{$disk}")) {
                $this->error("Disk not configured: {$disk}");

                return self::FAILURE;
            }
        }

        $query = SoundFile::where('disk', $from)
            ->when($this->option('purpose'), fn ($q, $p) => $q->where('purpose', $p));

        $total = $query->count();

        if ($total === 0) {
            $this->warn("Nothing stored on disk “{$from}”.");

            return self::SUCCESS;
        }

        $this->info(($dry ? '[dry run] ' : '')."Moving {$total} file(s): {$from} → {$to}");

        $bar = $this->output->createProgressBar($total);
        $moved = 0;
        $failed = 0;

        $query->chunkById(100, function ($files) use ($from, $to, $dry, $bar, &$moved, &$failed) {
            foreach ($files as $file) {
                try {
                    if (! Storage::disk($from)->exists($file->path)) {
                        $this->newLine();
                        $this->warn("Missing on source, skipped: {$file->path}");
                        $failed++;
                        $bar->advance();

                        continue;
                    }

                    if (! $dry) {
                        // Streamed, not read into memory: a 200 MB master
                        // would otherwise blow the PHP memory limit.
                        Storage::disk($to)->writeStream(
                            $file->path,
                            Storage::disk($from)->readStream($file->path)
                        );

                        // The database only changes once the copy exists.
                        // Fail between the two and the file is duplicated,
                        // which is recoverable. The other order loses it.
                        $file->update(['disk' => $to]);

                        if (! $this->option('keep')) {
                            Storage::disk($from)->delete($file->path);
                        }
                    }

                    $moved++;
                } catch (Throwable $e) {
                    $this->newLine();
                    $this->error("Failed on {$file->path}: {$e->getMessage()}");
                    $failed++;
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        $this->info("Moved: {$moved}".($failed ? " · Failed: {$failed}" : ''));

        if ($dry) {
            $this->comment('Dry run: nothing was changed.');
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
