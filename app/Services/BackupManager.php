<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;
use ZipArchive;

/**
 * Making copies, keeping the newest few, and putting one back.
 *
 * The frequency lives in the database so it can be changed from the panel,
 * which means the scheduler cannot hold it: a schedule is written once at
 * boot and this changes at runtime. So the command runs EVERY HOUR and asks
 * itself whether this is its hour — the same arrangement the weekly digest
 * already uses, and the only one that lets a setting mean anything.
 */
class BackupManager
{
    /** Anything the safety net makes is exempt from the rotation. */
    public const SAFETY_PREFIX = 'before-restore-';

    /* ═════════════════════════════ Schedule ═════════════════════════════ */

    public function frequency(): string
    {
        return (string) Setting::read('backup.frequency', 'daily');
    }

    public function hour(): int
    {
        return (int) Setting::read('backup.hour', 2);
    }

    public function keep(): int
    {
        return max(1, (int) Setting::read('backup.keep', 3));
    }

    /**
     * Is this the hour?
     *
     * Weekly means Monday, monthly means the first of the month. Both are
     * arbitrary and both are stated rather than clever: "the first Monday
     * after the last successful run" is the kind of rule that is impossible
     * to predict and impossible to test.
     */
    public function isDue(?Carbon $now = null): bool
    {
        $now ??= Carbon::now();

        if ($now->hour !== $this->hour()) {
            return false;
        }

        return match ($this->frequency()) {
            'daily' => true,
            'weekly' => $now->isMonday(),
            'monthly' => $now->day === 1,
            default => false,      // 'off'
        };
    }

    /* ══════════════════════════════ Making ══════════════════════════════ */

    /**
     * Run a database dump now.
     *
     * @param  string|null  $safetyLabel  set for the copy taken before a
     *                                    restore, which must survive the
     *                                    rotation that is about to run
     */
    public function run(?string $safetyLabel = null): string
    {
        $before = $this->filenames();

        Artisan::call('backup:run', ['--only-db' => true]);

        $created = array_values(array_diff($this->filenames(), $before));
        $newest = $created[0] ?? null;

        if ($newest && $safetyLabel) {
            $newest = $this->rename($newest, self::SAFETY_PREFIX.basename($newest));
        }

        if (! $safetyLabel) {
            $this->rotate();
        }

        return $newest ?? '';
    }

    /**
     * Keep the newest N, delete the rest.
     *
     * Spatie's own cleanup thins by age — dense for a week, sparse beyond —
     * which is a good default and not what was asked for. A flat count is a
     * different promise and needs its own implementation; mixing the two
     * would leave a strategy that keeps neither number.
     *
     * Safety copies are excluded. They exist precisely because something is
     * going wrong, and being rotated out by the very operation they are
     * protecting would be the worst possible timing.
     */
    public function rotate(): int
    {
        $disk = Storage::disk('backups');

        $rotatable = collect($this->backups())
            ->reject(fn ($b) => str_starts_with($b['name'], self::SAFETY_PREFIX))
            ->values();

        $doomed = $rotatable->slice($this->keep());

        foreach ($doomed as $backup) {
            $disk->delete($backup['path']);
        }

        return $doomed->count();
    }

    /**
     * Every archive, newest first.
     *
     * Read from the disk, never from a table. The files ARE the record, and
     * a row claiming a backup exists beside a disk where it does not is the
     * one lie this whole feature must never tell.
     *
     * @return array<int, array<string, mixed>>
     */
    public function backups(): array
    {
        try {
            $disk = Storage::disk('backups');

            return collect($disk->allFiles())
                ->filter(fn ($path) => str_ends_with($path, '.zip'))
                ->map(fn ($path) => [
                    'path' => $path,
                    'name' => basename($path),
                    'bytes' => $disk->size($path),
                    'at' => Carbon::createFromTimestamp($disk->lastModified($path)),
                    'safety' => str_starts_with(basename($path), self::SAFETY_PREFIX),
                ])
                ->sortByDesc(fn ($b) => $b['at']->timestamp)
                ->values()
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array<int, string> */
    private function filenames(): array
    {
        return array_column($this->backups(), 'path');
    }

    private function rename(string $path, string $newName): string
    {
        $target = trim(dirname($path), '.').'/'.$newName;
        $target = ltrim($target, '/');

        Storage::disk('backups')->move($path, $target);

        return $target;
    }

    /* ═════════════════════════════ Restoring ═════════════════════════════ */

    /**
     * Put a copy back over the live database.
     *
     * The order is the whole design, and every step is there because of what
     * happens without it:
     *
     *   1. A SAFETY COPY FIRST. If this is the wrong archive — and the
     *      archive is chosen from a list of near-identical filenames — the
     *      only way back is a copy of what was there a second ago. It is
     *      exempt from rotation, because it is protecting the operation that
     *      would otherwise delete it.
     *
     *   2. IMPORT THROUGH THE mysql CLIENT, not PDO. A dump is not a list of
     *      statements you can split on semicolons: it has triggers, routines
     *      and semicolons inside strings. Every hand-rolled splitter works
     *      until the day it does not, and that day is this one.
     *
     *   3. MIGRATE AFTERWARDS. Restoring a two-month-old dump leaves a
     *      schema older than the code, which breaks the site in a completely
     *      different way from the one you were fixing.
     *
     *   4. LOG IT AFTER, NOT BEFORE. The activity log is a table like any
     *      other and the restore overwrites it, so an entry written first
     *      would be erased by the thing it was recording.
     *
     * No maintenance mode, deliberately. `artisan down` risks stranding the
     * site if this process dies between down and up, and on a database this
     * size the import is about a second. Trading a certain lockout risk for
     * an unlikely concurrent write is the wrong way round.
     */
    public function restore(string $path): array
    {
        $disk = Storage::disk('backups');

        if (! $disk->exists($path)) {
            throw new RuntimeException('That backup is no longer on disk.');
        }

        $safety = $this->run(safetyLabel: 'before-restore');

        $workDir = storage_path('app/backup-temp/restore-'.\Illuminate\Support\Str::random(8));
        @mkdir($workDir, 0755, true);

        try {
            $sql = $this->extractDump($disk->path($path), $workDir);

            $this->importSql($sql);

            // The schema may be older than the code that is about to run.
            Artisan::call('migrate', ['--force' => true]);

            ActivityLog::record(
                'backup.restored',
                null,
                'Database restored from '.basename($path),
                ['archive' => basename($path), 'safety_copy' => basename($safety)],
            );

            return ['safety' => $safety];
        } finally {
            $this->deleteDirectory($workDir);
        }
    }

    /** Pull the .sql out of the archive and return its path. */
    private function extractDump(string $zipPath, string $workDir): string
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('The archive could not be opened. It may be incomplete or password-protected.');
        }

        $entry = null;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            if (str_ends_with($name, '.sql') || str_ends_with($name, '.sql.gz')) {
                $entry = $name;
                break;
            }
        }

        if (! $entry) {
            $zip->close();

            throw new RuntimeException('No database dump inside that archive — it may be a files-only backup.');
        }

        $zip->extractTo($workDir, [$entry]);
        $zip->close();

        $extracted = $workDir.'/'.$entry;

        if (! str_ends_with($extracted, '.gz')) {
            return $extracted;
        }

        // Decompress in chunks: a dump is text and can be far larger than
        // memory once expanded.
        $plain = substr($extracted, 0, -3);
        $in = gzopen($extracted, 'rb');
        $out = fopen($plain, 'wb');

        while (! gzeof($in)) {
            fwrite($out, gzread($in, 262144));
        }

        gzclose($in);
        fclose($out);

        return $plain;
    }

    /**
     * Feed the dump to mysql.
     *
     * Credentials go in a temporary defaults file, never on the command
     * line: arguments are visible to every process on the machine through
     * ps, and a database password sitting in another user's process list is
     * a password you have given away.
     */
    private function importSql(string $sqlPath): void
    {
        $config = config('database.connections.'.config('database.default'));

        $defaults = tempnam(sys_get_temp_dir(), 'dbelo-my-');

        file_put_contents($defaults, implode("\n", [
            '[client]',
            'user='.$config['username'],
            'password="'.($config['password'] ?? '').'"',
            'host='.($config['host'] ?? '127.0.0.1'),
            'port='.($config['port'] ?? 3306),
            '',
        ]));

        chmod($defaults, 0600);

        try {
            $process = Process::fromShellCommandline(
                sprintf(
                    'mysql --defaults-extra-file=%s %s < %s',
                    escapeshellarg($defaults),
                    escapeshellarg($config['database']),
                    escapeshellarg($sqlPath),
                ),
            );

            $process->setTimeout(600);
            $process->run();

            if (! $process->isSuccessful()) {
                throw new RuntimeException(
                    'The import failed: '.trim($process->getErrorOutput() ?: $process->getOutput()),
                );
            }

            // The connection was holding the old schema's metadata.
            DB::reconnect();
        } finally {
            @unlink($defaults);
        }
    }

    private function deleteDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (glob($dir.'/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            } elseif (is_dir($file) && ! in_array(basename($file), ['.', '..'], true)) {
                $this->deleteDirectory($file);
            }
        }

        @rmdir($dir);
    }
}
