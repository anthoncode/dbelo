<?php

namespace App\Console\Commands;

use App\Jobs\ProcessSoundUpload;
use App\Models\Sound;
use Illuminate\Console\Command;

/**
 * Re-queues sounds whose processing failed. Useful after fixing ffmpeg,
 * freeing disk space, or correcting a bad upload.
 */
class RetryFailedSoundsCommand extends Command
{
    protected $signature = 'sounds:retry {--id= : Retry only this sound}';

    protected $description = 'Re-queue sounds that failed to process';

    public function handle(): int
    {
        $sounds = Sound::whereNotNull('processing_error')
            ->when($this->option('id'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        if ($sounds->isEmpty()) {
            $this->info('Nothing to retry.');

            return self::SUCCESS;
        }

        foreach ($sounds as $sound) {
            $sound->update(['processing_error' => null, 'status' => 'draft']);
            ProcessSoundUpload::dispatch($sound);
            $this->line("  <info>↻</info> {$sound->title}");
        }

        $this->info("Re-queued {$sounds->count()} sound(s).");

        return self::SUCCESS;
    }
}
