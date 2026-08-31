<?php

namespace App\Services;

use App\Models\SoundFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * What the Storage screen knows.
 *
 * Two questions this answers, and they are different questions:
 *
 *   1. Can we reach this disk RIGHT NOW? Not "is it configured" — a filled-in
 *      set of credentials that has never moved a byte is worth nothing. The
 *      only honest answer is to write a file, read it back, and delete it.
 *   2. What is actually stored, where? Read from sound_files, which is the
 *      application's own record, not from the provider — those two can
 *      disagree, and the disagreement is the interesting part.
 */
class StorageHealth
{
    /**
     * Disks this application knows how to talk about, in the order they
     * belong on screen. Anything else in config/filesystems.php is
     * framework furniture and is not the operator's concern.
     */
    public const DISKS = [
        'sounds_private' => ['label' => 'Audio (local)', 'note' => 'Masters and paid downloads, outside public/'],
        'public' => ['label' => 'Public (local)', 'note' => 'Previews and images served directly'],
        'r2' => ['label' => 'Cloudflare R2', 'note' => 'S3 protocol · egress not charged'],
        'b2' => ['label' => 'Backblaze B2', 'note' => 'S3 protocol · cheapest per stored GB'],
        's3' => ['label' => 'Amazon S3 / other', 'note' => 'Generic S3 endpoint'],
    ];

    /** How long a passing check is trusted before it is run again. */
    private const CACHE_TTL_SECONDS = 300;

    private const CACHE_PREFIX = 'storage.health.';

    /**
     * One row per disk for the screen.
     *
     * @return array<int, array<string, mixed>>
     */
    public function disks(): array
    {
        $default = config('filesystems.default');
        $rows = [];

        foreach (self::DISKS as $name => $meta) {
            $config = config("filesystems.disks.{$name}");

            if (! $config) {
                continue;
            }

            $rows[] = [
                'name' => $name,
                'label' => $meta['label'],
                'note' => $meta['note'],
                'driver' => $config['driver'] ?? '?',
                'configured' => $this->isConfigured($name, $config),
                'isDefault' => $name === $default,
                'endpoint' => $this->endpointFor($config),
                'bucket' => $config['bucket'] ?? null,
                'check' => $this->cachedCheck($name),
            ];
        }

        return $rows;
    }

    /**
     * Are the credentials even present?
     *
     * Separate from the live check on purpose. "Not configured" is a task
     * for the operator; "configured but unreachable" is a bug or an outage,
     * and showing both as one red dot would hide which of the two it is.
     */
    private function isConfigured(string $name, array $config): bool
    {
        if (($config['driver'] ?? null) === 'local') {
            return true;
        }

        foreach (['key', 'secret', 'bucket'] as $required) {
            if (blank($config[$required] ?? null)) {
                return false;
            }
        }

        // R2 and B2 are only reachable through their own endpoint. AWS is
        // the one S3 provider that works without one.
        return $name === 's3' || filled($config['endpoint'] ?? null);
    }

    private function endpointFor(array $config): ?string
    {
        if (filled($config['endpoint'] ?? null)) {
            return $config['endpoint'];
        }

        return ($config['driver'] ?? null) === 'local'
            ? ($config['root'] ?? null)
            : null;
    }

    /** @return array<string, mixed> */
    public function cachedCheck(string $disk): array
    {
        return Cache::get(self::CACHE_PREFIX.$disk) ?? [
            'state' => 'unknown',
            'ms' => null,
            'error' => null,
            'at' => null,
        ];
    }

    /**
     * Write, read back, delete. The only proof that works.
     *
     * A round trip catches what a credentials check cannot: a bucket that
     * does not exist, a key with read-only permissions, a wrong region, an
     * endpoint that resolves but belongs to someone else's account. Every
     * one of those looks perfectly configured from the outside.
     *
     * @return array<string, mixed>
     */
    public function check(string $disk): array
    {
        $config = config("filesystems.disks.{$disk}");

        if (! $config) {
            return $this->store($disk, 'fail', null, "There is no disk called \"{$disk}\".");
        }

        /*
         * Built by hand with throw => true.
         *
         * Every disk in this project sets throw => false so a failed write
         * in normal running returns false instead of taking the request
         * down. That is right everywhere except here: on a diagnostics
         * screen the provider's own error message IS the answer, and
         * "false" is the one reply that helps nobody.
         */
        $config['throw'] = true;

        $path = '.dbelo-health/'.Str::random(24).'.txt';
        $payload = 'dbelo storage check '.now()->toIso8601String();
        $startedAt = microtime(true);

        try {
            $fs = Storage::build($config);

            $fs->put($path, $payload);
            $readBack = $fs->get($path);
            $fs->delete($path);

            $elapsed = (int) round((microtime(true) - $startedAt) * 1000);

            if ($readBack !== $payload) {
                // Rare, and worth its own message: a disk that accepts a
                // write and hands back something else is more dangerous
                // than one that refuses the write.
                return $this->store($disk, 'fail', $elapsed, 'The file was written but read back different.');
            }

            return $this->store($disk, 'ok', $elapsed, null);
        } catch (Throwable $e) {
            $elapsed = (int) round((microtime(true) - $startedAt) * 1000);

            // Attempt a cleanup, but never let the cleanup replace the real
            // error: the first exception is the one worth reporting.
            try {
                Storage::disk($disk)->delete($path);
            } catch (Throwable) {
                // Nothing to do — the disk is already known to be broken.
            }

            return $this->store($disk, 'fail', $elapsed, $this->readable($e));
        }
    }

    /**
     * Provider exceptions arrive as a wall of XML and stack context. The
     * first line is almost always the sentence a person needs.
     */
    private function readable(Throwable $e): string
    {
        $message = trim(Str::before($e->getMessage(), "\n"));

        return Str::limit($message ?: $e::class, 300);
    }

    /** @return array<string, mixed> */
    private function store(string $disk, string $state, ?int $ms, ?string $error): array
    {
        $result = [
            'state' => $state,
            'ms' => $ms,
            'error' => $error,
            'at' => now()->toIso8601String(),
        ];

        /*
         * A plain array, never a model or an Eloquent collection. Cached
         * objects go through PHP serialisation, and the NUL bytes in
         * protected-property keys do not survive a MySQL text column — the
         * failure shows up on the SECOND read, which is the worst possible
         * time to discover it.
         *
         * A failure is remembered for a fifth of the time a success is: when
         * something is broken you retry it, when it works you leave it alone.
         */
        Cache::put(
            self::CACHE_PREFIX.$disk,
            $result,
            now()->addSeconds($state === 'ok' ? self::CACHE_TTL_SECONDS : (int) (self::CACHE_TTL_SECONDS / 5)),
        );

        return $result;
    }

    /**
     * What the catalogue weighs, per disk and per kind of file.
     *
     * Counted from sound_files rather than from the provider. The provider
     * knows what is in the bucket; this table knows what the application
     * believes is in the bucket. Reporting the application's belief is what
     * makes the two comparable at all.
     *
     * @return array{rows: array<int, array<string, mixed>>, totals: array<string, int>}
     */
    public function usage(): array
    {
        if (! Schema::hasTable('sound_files')) {
            return ['rows' => [], 'totals' => ['files' => 0, 'bytes' => 0]];
        }

        $rows = DB::table('sound_files')
            ->selectRaw('disk, purpose, COUNT(*) as files, COALESCE(SUM(size_bytes), 0) as bytes')
            ->groupBy('disk', 'purpose')
            ->orderBy('disk')
            ->orderBy('purpose')
            ->get()
            ->map(fn ($row) => [
                'disk' => $row->disk,
                'purpose' => $row->purpose,
                'files' => (int) $row->files,
                'bytes' => (int) $row->bytes,
            ])
            ->all();

        return [
            'rows' => $rows,
            'totals' => [
                'files' => array_sum(array_column($rows, 'files')),
                'bytes' => array_sum(array_column($rows, 'bytes')),
            ],
        ];
    }

    /**
     * Rows whose file is not where the database says it is.
     *
     * Deliberately NOT run on page load. One exists() call per row is one
     * network round trip per row on a remote disk; on a catalogue of any
     * size that turns a dashboard into an outage of its own making. It is
     * an action the operator asks for, over a bounded slice.
     *
     * @return array{checked: int, missing: array<int, array<string, mixed>>, complete: bool}
     */
    public function scanForMissing(int $limit = 250, int $offset = 0): array
    {
        if (! Schema::hasTable('sound_files')) {
            return ['checked' => 0, 'missing' => [], 'complete' => true];
        }

        $files = SoundFile::query()
            ->with('sound:id,title,slug')
            ->orderBy('id')
            ->offset($offset)
            ->limit($limit)
            ->get();

        $missing = [];

        foreach ($files as $file) {
            try {
                $exists = Storage::disk($file->disk)->exists($file->path);
            } catch (Throwable) {
                // An unreachable disk is not a missing file. Saying so would
                // invite someone to delete perfectly good rows because a
                // bucket was briefly down.
                continue;
            }

            if (! $exists) {
                $missing[] = [
                    'id' => $file->id,
                    'sound_id' => $file->sound_id,
                    'title' => $file->sound?->title ?? 'Deleted sound',
                    'purpose' => $file->purpose,
                    'disk' => $file->disk,
                    'path' => $file->path,
                ];
            }
        }

        return [
            'checked' => $files->count(),
            'missing' => $missing,
            'complete' => $files->count() < $limit,
        ];
    }

    /** Total rows, so the scan can report progress honestly. */
    public function fileCount(): int
    {
        return Schema::hasTable('sound_files') ? (int) DB::table('sound_files')->count() : 0;
    }

    public static function bytesForHumans(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = min((int) floor(log($bytes, 1024)), count($units) - 1);
        $value = $bytes / (1024 ** $power);

        return round($value, $power >= 2 ? 1 : 0).' '.$units[$power];
    }

    /**
     * What R2 would cost for what is stored today.
     *
     * A number, not a range, and only for the provider we intend to use.
     * The reason this is on the screen at all: the fear that stops people
     * moving audio off the web server is the bill, and for a catalogue this
     * size the bill is smaller than the fear.
     *
     * $0.015 per GB-month, first 10 GB free. Egress is not charged, which
     * is the part that matters for audio and the part no estimate can show.
     */
    public static function monthlyEstimate(int $bytes): array
    {
        $gb = $bytes / 1_073_741_824;
        $billable = max(0, $gb - 10);

        return [
            'gb' => $gb,
            'freeTier' => $gb <= 10,
            'usd' => round($billable * 0.015, 2),
        ];
    }
}
