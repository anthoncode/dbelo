<?php

namespace App\Jobs;

use App\Models\Sound;
use App\Models\SoundFile;
use App\Services\AudioProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Turns a freshly uploaded master file into everything the site needs:
 * metadata, a streamable preview, a downloadable MP3 and the waveform.
 *
 * This runs on a queue because ffmpeg takes 10-40 seconds on a big WAV,
 * far longer than a web request is allowed to live.
 */
class ProcessSoundUpload implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 3;

    public function __construct(public Sound $sound) {}

    public function handle(AudioProcessor $audio): void
    {
        $original = $this->sound->files()->where('purpose', 'original')->first();

        if (! $original) {
            $this->fail(new \RuntimeException('Sound has no original file to process.'));

            return;
        }

        $this->sound->update(['status' => 'processing']);

        $sourcePath = Storage::disk($original->disk)->path($original->path);

        // 1. Technical metadata
        $meta = $audio->probe($sourcePath);

        // 2. Public preview: 128 kbps MP3, streamed freely to everyone.
        $previewPath = "sounds/previews/{$this->sound->uuid}.mp3";
        $audio->makePreview($sourcePath, Storage::disk('public')->path($previewPath));

        SoundFile::updateOrCreate(
            [
                'sound_id' => $this->sound->id,
                'purpose' => 'preview',
                'format' => 'mp3',
            ],
            [
                'disk' => 'public',
                'path' => $previewPath,
                'bitrate' => 128,
                'sample_rate' => 44100,
                'size_bytes' => Storage::disk('public')->size($previewPath),
            ]
        );

        // 3. Downloadable MP3: 320 kbps, kept private and served only
        //    through the controller that checks the daily quota.
        $downloadPath = "downloads/{$this->sound->uuid}.mp3";
        $audio->makeDownload($sourcePath, Storage::disk('sounds_private')->path($downloadPath));

        SoundFile::updateOrCreate(
            [
                'sound_id' => $this->sound->id,
                'purpose' => 'download',
                'format' => 'mp3',
            ],
            [
                'disk' => 'sounds_private',
                'path' => $downloadPath,
                'bitrate' => 320,
                'sample_rate' => 44100,
                'size_bytes' => Storage::disk('sounds_private')->size($downloadPath),
            ]
        );

        // 4. Waveform peaks for the player.
        $waveform = $audio->waveform($sourcePath);

        // 5. Ready for review.
        $this->sound->update([
            'duration_ms' => $meta['duration_ms'],
            'sample_rate' => $meta['sample_rate'],
            'bit_depth' => $meta['bit_depth'],
            'channels' => $meta['channels'],
            'waveform' => $waveform,
            'status' => 'pending',
            'processing_error' => null,
            'processed_at' => now(),
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error("Sound {$this->sound->id} failed to process", [
            'message' => $exception?->getMessage(),
        ]);

        $this->sound->update([
            'status' => 'draft',
            'processing_error' => $exception?->getMessage(),
        ]);
    }
}
