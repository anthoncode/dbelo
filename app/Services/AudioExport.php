<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;
use ZipStream\ZipStream;

/**
 * Taking the audio out, by hand, in pieces a person chooses.
 *
 * The database is backed up on a schedule because it is small and the whole
 * of it is always the right answer. Audio is neither: it is measured in tens
 * or hundreds of gigabytes, and most nights none of it has changed. A
 * nightly zip of it would fill the disk it is stored on and quietly stop the
 * only backup that was working.
 *
 * So this is the other shape — an export, not a backup. Three things make it
 * usable rather than a button that hangs:
 *
 *   1. IT IS STREAMED. The zip is written to the response as it is built,
 *      one file at a time. Memory stays flat whether the selection is 40 MB
 *      or 40 GB, and nothing is ever written to the server's disk — which
 *      matters, because a machine with 12 GB free cannot make a 14 GB
 *      archive to hand you afterwards.
 *
 *   2. THE SIZE IS KNOWN BEFORE YOU COMMIT. sound_files.size_bytes is
 *      already there, so the count and the total are one query. Being told
 *      "1,240 files · 14.2 GB" before clicking is the difference between a
 *      decision and a gamble.
 *
 *   3. IT REFUSES WHAT IT CANNOT FINISH. A browser download measured in tens
 *      of gigabytes fails on the first interruption and there is no resume.
 *      Past the cap it says so and asks you to narrow the selection, which
 *      is a worse answer than "yes" and a far better one than three wasted
 *      hours.
 */
class AudioExport
{
    /**
     * Above this, refuse and ask for a narrower selection.
     *
     * Not a technical limit — the stream itself does not care. It is the
     * point past which a single browser download stops being a reasonable
     * way to move data, and rclone against the bucket becomes the honest
     * advice.
     */
    public const MAX_BYTES = 8 * 1024 * 1024 * 1024;   // 8 GB

    /**
     * What a person can ask for.
     *
     * `original` first and default: previews and the 320 MP3s are DERIVED —
     * ffmpeg rebuilds them from the master. Exporting all three triples the
     * download to carry two copies of what the third can regenerate.
     */
    public const PURPOSES = [
        'original' => 'Masters only — previews and MP3s can be rebuilt from these',
        'preview' => 'Previews',
        'download' => 'Download files (MP3 320)',
    ];

    /**
     * @param  array<int, string>  $purposes
     */
    public function query(array $purposes, ?int $categoryId = null, ?int $collectionId = null, ?string $since = null): Builder
    {
        $query = DB::table('sound_files')
            ->join('sounds', 'sounds.id', '=', 'sound_files.sound_id')
            ->whereNull('sounds.deleted_at')
            ->whereIn('sound_files.purpose', $purposes ?: ['original']);

        if ($categoryId) {
            $query->where('sounds.category_id', $categoryId);
        }

        if ($collectionId) {
            // A pack is a collection; membership lives in the pivot.
            $query->whereExists(fn ($q) => $q
                ->select(DB::raw(1))
                ->from('collection_sound')
                ->whereColumn('collection_sound.sound_id', 'sounds.id')
                ->where('collection_sound.collection_id', $collectionId));
        }

        if ($since) {
            $query->where('sounds.created_at', '>=', $since);
        }

        return $query;
    }

    /**
     * Count and total size, without touching a single file.
     *
     * @return array{files: int, bytes: int}
     */
    public function measure(array $purposes, ?int $categoryId = null, ?int $collectionId = null, ?string $since = null): array
    {
        if (! Schema::hasTable('sound_files')) {
            return ['files' => 0, 'bytes' => 0];
        }

        $row = $this->query($purposes, $categoryId, $collectionId, $since)
            ->selectRaw('COUNT(*) as files, COALESCE(SUM(sound_files.size_bytes), 0) as bytes')
            ->first();

        return [
            'files' => (int) ($row->files ?? 0),
            'bytes' => (int) ($row->bytes ?? 0),
        ];
    }

    /**
     * Stream the selection as a zip.
     *
     * Files are read from whatever disk each row names, so a catalogue half
     * migrated to R2 exports in one archive without knowing it is split.
     *
     * A file that cannot be read is SKIPPED, not fatal. Twenty minutes into
     * a download, one missing master must not throw away everything that
     * came before it — the export is still worth having, and the missing
     * file is a job for the Storage screen's scan.
     */
    public function stream(array $purposes, ?int $categoryId = null, ?int $collectionId = null, ?string $since = null): void
    {
        $zip = new ZipStream(
            // streamDownload() has already sent the headers.
            sendHttpHeaders: false,
            // Sizes are not known up front when reading from a stream, so
            // the archive is written with data descriptors.
            defaultEnableZeroHeader: true,
        );

        $used = [];

        $this->query($purposes, $categoryId, $collectionId, $since)
            ->select('sound_files.disk', 'sound_files.path', 'sound_files.purpose',
                'sounds.title', 'sounds.slug', 'sounds.id as sound_id')
            ->orderBy('sound_files.id')
            ->chunk(200, function ($rows) use ($zip, &$used) {
                foreach ($rows as $row) {
                    try {
                        $handle = Storage::disk($row->disk)->readStream($row->path);

                        if (! $handle) {
                            continue;
                        }

                        $zip->addFileFromStream(
                            fileName: $this->nameFor($row, $used),
                            stream: $handle,
                        );

                        if (is_resource($handle)) {
                            fclose($handle);
                        }
                    } catch (Throwable $e) {
                        Log::warning('Audio export skipped a file', [
                            'disk' => $row->disk,
                            'path' => $row->path,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        $zip->finish();
    }

    /**
     * A name a person can use once the zip is open.
     *
     * Foldered by purpose and named from the slug, because the stored path
     * is a hash-shaped thing that means nothing outside the application. The
     * id is appended when two sounds share a slug, and a counter guards the
     * rest — a duplicate name inside a zip is a file the archive silently
     * loses on extraction.
     *
     * @param  array<string, int>  $used
     */
    private function nameFor(object $row, array &$used): string
    {
        $extension = pathinfo($row->path, PATHINFO_EXTENSION) ?: 'bin';
        $base = Str::slug($row->slug ?: $row->title ?: 'sound') ?: 'sound';
        $name = "{$row->purpose}/{$base}-{$row->sound_id}.{$extension}";

        if (isset($used[$name])) {
            $used[$name]++;
            $name = "{$row->purpose}/{$base}-{$row->sound_id}-{$used[$name]}.{$extension}";
        } else {
            $used[$name] = 1;
        }

        return $name;
    }

    public static function filename(): string
    {
        return 'dbelo-audio-'.now()->format('Y-m-d-His').'.zip';
    }
}
