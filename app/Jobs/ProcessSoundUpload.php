<?php

namespace App\Jobs;

use App\Models\Sound;
use App\Models\SoundFile;
use App\Services\AudioProcessor;
use App\Support\AutoTags;
use App\Support\Suggestions;
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

    /*
    | This job used to put itself on a separate "audio" queue, so a bulk
    | import could not hold up notifications. It was removed, and the reason
    | is worth keeping:
    |
    | A named queue is only consumed by a worker started with the matching
    | --queue flag. Any worker started without it — a plain `queue:work` in
    | a second terminal, a deploy script, a cron line copied from the docs —
    | silently converts NOTHING. The failure is invisible: no error, no
    | failed job, just sounds that say "converting" forever.
    |
    | The delay it avoided is theoretical right now (one machine, no mail
    | traffic). The way it breaks is not. Split the queues again when there
    | is a real worker configuration to put it in, and add a check that
    | warns about jobs sitting on a queue nobody is listening to.
    */
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

        $previewDisk = config('dbelo.storage.preview');
        $downloadDisk = config('dbelo.storage.download');

        // 1. Technical metadata
        $meta = $audio->probe($sourcePath);

        // A file that decodes to nothing is not worth three transcodes and a
        // moderator's time. Fail it here with a reason the uploader can act on.
        if ($meta['duration_ms'] < 100) {
            throw new \RuntimeException('The file is shorter than 0.1 seconds, or the audio could not be read.');
        }

        // 2. Public preview: 128 kbps MP3, streamed freely to everyone.
        $previewPath = "sounds/previews/{$this->sound->uuid}.mp3";
        $audio->makePreview($sourcePath, $this->workPath($previewDisk, $previewPath));

        $this->flushUploads();

        SoundFile::updateOrCreate(
            [
                'sound_id' => $this->sound->id,
                'purpose' => 'preview',
                'format' => 'mp3',
            ],
            [
                'disk' => $previewDisk,
                'path' => $previewPath,
                'bitrate' => 128,
                'sample_rate' => 44100,
                'size_bytes' => Storage::disk($previewDisk)->size($previewPath),
            ]
        );

        // 3. Downloadable MP3: 320 kbps, kept private and served only
        //    through the controller that checks the daily quota.
        $downloadPath = "downloads/{$this->sound->uuid}.mp3";
        $audio->makeDownload($sourcePath, $this->workPath($downloadDisk, $downloadPath));

        $this->flushUploads();

        SoundFile::updateOrCreate(
            [
                'sound_id' => $this->sound->id,
                'purpose' => 'download',
                'format' => 'mp3',
            ],
            [
                'disk' => $downloadDisk,
                'path' => $downloadPath,
                'bitrate' => 320,
                'sample_rate' => 44100,
                'size_bytes' => Storage::disk($downloadDisk)->size($downloadPath),
            ]
        );

        // 4. Waveform peaks for the player.
        $waveform = $audio->waveform($sourcePath);

        // Peaks under 1% across the whole file means silence: usually a bad
        // export or the wrong input selected while recording.
        if ($waveform !== [] && max($waveform) < 0.01) {
            throw new \RuntimeException('The file appears to be silent. Check the export or the recording input.');
        }

        // 5. Done. Where it lands was decided when it was uploaded:
        //    straight onto the site, or into the review queue.
        $publish = (bool) $this->sound->publish_when_ready;

        $this->sound->update([
            'duration_ms' => $meta['duration_ms'],
            'sample_rate' => $meta['sample_rate'],
            'bit_depth' => $meta['bit_depth'],
            'channels' => $meta['channels'],
            'waveform' => $waveform,
            'status' => $publish ? 'published' : 'pending',
            'published_at' => $publish ? ($this->sound->published_at ?? now()) : $this->sound->published_at,
            'processing_error' => null,
            'processed_at' => now(),
        ]);

        /*
         * 6. Tags, before anything else has a chance not to happen.
         *
         * Taken from the title and the category, so they cost nothing, need
         * no API key and cannot be invented — every word is already in the
         * data. A sound with no tags is a sound the search barely finds, and
         * that state must not depend on a model answering.
         *
         * Tops up only: never removes a tag, and does nothing at all if the
         * sound already has three. The suggester adds its own on top later.
         */
        try {
            AutoTags::topUp($this->sound);
        } catch (Throwable $e) {
            Log::warning('Could not auto-tag sound', [
                'sound' => $this->sound->id,
                'error' => $e->getMessage(),
            ]);
        }

        /*
         * 7. Ask a model for better tags, a category and a description.
         *
         * The tags it returns ARE written to the sound. The description and
         * the category are not — they wait in review, because a false
         * sentence on a public page costs more than a loose tag in a search
         * box. SuggestSoundMetadata has the full argument.
         *
         * ── WHY THE READY() CHECK BEFORE DISPATCHING ─────────────────────
         *
         * With no API key the job would throw notConfigured on every single
         * upload, and fifty files would leave fifty rows in failed_jobs — a
         * table that has already misled us once on this project by looking
         * alarming when nothing was actually broken. A missing key is a
         * normal state, so it must not manufacture failures.
         *
         * The job checks again when it runs, because a key can be removed in
         * the minutes between this line and the worker picking it up.
         *
         * Wrapped, because a suggestion is the least important thing that
         * happens in this method. A sound whose conversion worked must never
         * be marked failed because an optional extra threw.
         */
        try {
            if (Suggestions::ready()) {
                SuggestSoundMetadata::dispatch($this->sound->id);
            }
        } catch (Throwable $e) {
            Log::warning('Could not queue metadata suggestions', [
                'sound' => $this->sound->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * ffmpeg writes to the filesystem, not to a stream, so a remote disk
     * needs a local scratch file that is uploaded afterwards.
     */
    protected function workPath(string $disk, string $path): string
    {
        if (config("filesystems.disks.{$disk}.driver") === 'local') {
            return Storage::disk($disk)->path($path);
        }

        $temp = sys_get_temp_dir().'/'.basename($path);

        $this->pendingUploads[] = [$disk, $path, $temp];

        return $temp;
    }

    /** @var array<int, array{0:string,1:string,2:string}> */
    protected array $pendingUploads = [];

    protected function flushUploads(): void
    {
        foreach ($this->pendingUploads as [$disk, $path, $temp]) {
            Storage::disk($disk)->put($path, file_get_contents($temp));
            @unlink($temp);
        }

        $this->pendingUploads = [];
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
