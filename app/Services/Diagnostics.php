<?php

namespace App\Services;

use App\Support\UploadLimits;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Everything that can be wrong without anything being thrown.
 *
 * This is the gap no other screen covers. Errors reports what crashed;
 * Queue reports background work; Storage reports files. Diagnostics is for
 * the third kind of failure, which is the worst kind: a mismatch between
 * the code and the environment it is running in, which produces WRONG
 * BEHAVIOUR rather than an exception. Nothing appears in any log, so the
 * only way to find it is to go looking — and going looking is what most
 * people never think to do, because from the inside it just feels like the
 * framework being unhelpful.
 *
 * Every check returns the same shape so the console command and the admin
 * screen can render the identical list. One definition, two surfaces: the
 * moment they are written twice they start disagreeing, and then neither
 * can be trusted.
 */
class Diagnostics
{
    public const OK = 'ok';

    public const WARN = 'warn';

    public const FAIL = 'fail';

    /** Cache key the scheduler stamps, so "is cron running" is answerable. */
    public const SCHEDULER_STAMP = 'diagnostics.scheduler';

    /**
     * @return array<int, array{key: string, group: string, label: string, status: string, detail: string, fix: ?string, command: ?string, route: ?string}>
     */
    public function run(): array
    {
        $checks = [];

        foreach ([
            'buildFreshness', 'buildAssets',
            'appKey', 'appDebug', 'configCache',
            'database', 'pendingMigrations',
            'queueDriver', 'scheduler',
            'mailDriver',
            'ffmpeg', 'uploadLimits',
            'searchEngine',
            'publicLink', 'writablePaths', 'exposedEnv',
            'phpExtensions',
        ] as $method) {
            try {
                $result = $this->{$method}();

                if ($result !== null) {
                    $checks[] = $result;
                }
            } catch (Throwable $e) {
                // A check that blows up must not take the screen with it.
                // The screen exists precisely for when things are broken.
                $checks[] = $this->make($method, 'System', $method, self::WARN,
                    'This check could not run: '.$e->getMessage());
            }
        }

        return $checks;
    }

    /** @return array{ok: int, warn: int, fail: int} */
    public function summary(?array $checks = null): array
    {
        $checks ??= $this->run();

        return [
            'ok' => count(array_filter($checks, fn ($c) => $c['status'] === self::OK)),
            'warn' => count(array_filter($checks, fn ($c) => $c['status'] === self::WARN)),
            'fail' => count(array_filter($checks, fn ($c) => $c['status'] === self::FAIL)),
        ];
    }

    /* ══════════════════════════ Front-end ══════════════════════════ */

    /**
     * Is the compiled CSS older than the code that needs it?
     *
     * The check that earns this whole screen. Tailwind only emits a class
     * that existed at BUILD time, so a stylesheet older than the Blade files
     * silently drops every new utility — padding vanishes, layouts collapse,
     * and nothing is logged anywhere because nothing failed. It presents as
     * "the design is broken", which sends you looking at the design.
     */
    private function buildFreshness(): array
    {
        if (file_exists(public_path('hot'))) {
            return $this->make('build.fresh', 'Front-end', 'Asset build', self::OK,
                'Vite dev server is running — assets rebuild on save.');
        }

        $manifest = public_path('build/manifest.json');

        if (! file_exists($manifest)) {
            return $this->make('build.fresh', 'Front-end', 'Asset build', self::FAIL,
                'No build has ever been made.',
                fix: 'Nothing will be styled until the assets are compiled.',
                command: 'npm run build');
        }

        $built = filemtime($manifest);
        $newest = $this->newestSourceTime();

        if ($newest <= $built) {
            return $this->make('build.fresh', 'Front-end', 'Asset build', self::OK,
                'Compiled '.$this->ago($built).', after the last code change.');
        }

        $behind = (int) round(($newest - $built) / 60);

        return $this->make('build.fresh', 'Front-end', 'Asset build', self::FAIL,
            "The stylesheet is {$behind} minutes older than the newest source file.",
            fix: 'Any CSS class written since then does not exist in the build, so those elements render unstyled — with no error anywhere. Leave the dev server running and this cannot happen again.',
            command: './dev.sh');
    }

    /** Newest mtime across the files Vite compiles from. */
    private function newestSourceTime(): int
    {
        return Cache::remember('diagnostics.source.mtime', now()->addMinute(), function () {
            $newest = 0;

            foreach (['views', 'css', 'js'] as $dir) {
                $path = resource_path($dir);

                if (! is_dir($path)) {
                    continue;
                }

                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
                );

                foreach ($iterator as $file) {
                    if ($file->isFile()) {
                        $newest = max($newest, $file->getMTime());
                    }
                }
            }

            return $newest;
        });
    }

    /**
     * A bundle that compiled to nothing.
     *
     * Vite can finish "successfully" and emit an empty file when the build
     * errored in a way nobody read. The page then loads a script of zero
     * bytes and every piece of interactivity is silently absent.
     */
    private function buildAssets(): ?array
    {
        $manifest = public_path('build/manifest.json');

        if (! file_exists($manifest)) {
            return null;   // already reported by buildFreshness
        }

        $entries = json_decode((string) file_get_contents($manifest), true) ?: [];
        $empty = [];

        foreach ($entries as $entry) {
            $file = public_path('build/'.($entry['file'] ?? ''));

            if (isset($entry['isEntry']) && file_exists($file) && filesize($file) === 0) {
                $empty[] = $entry['file'];
            }
        }

        if ($empty) {
            return $this->make('build.assets', 'Front-end', 'Compiled bundles', self::FAIL,
                'Empty bundle: '.implode(', ', $empty),
                fix: 'The build produced a zero-byte file, so everything in it is missing at runtime. Rebuild and read the output for errors.',
                command: 'npm run build');
        }

        return $this->make('build.assets', 'Front-end', 'Compiled bundles', self::OK,
            count($entries).' entries, none empty.');
    }

    /* ═══════════════════════════ Environment ═══════════════════════════ */

    private function appKey(): array
    {
        return filled(config('app.key'))
            ? $this->make('env.key', 'Environment', 'Application key', self::OK, 'Set.')
            : $this->make('env.key', 'Environment', 'Application key', self::FAIL,
                'APP_KEY is empty — sessions and encrypted columns cannot work.',
                command: 'php artisan key:generate');
    }

    private function appDebug(): array
    {
        $debug = (bool) config('app.debug');
        $production = app()->environment('production');

        if ($debug && $production) {
            return $this->make('env.debug', 'Environment', 'Debug mode', self::FAIL,
                'APP_DEBUG is on in production.',
                fix: 'Stack traces, environment variables and database credentials are shown to anyone who triggers an error. Set APP_DEBUG=false.');
        }

        return $this->make('env.debug', 'Environment', 'Debug mode', self::OK,
            $debug ? 'On, and this is not production.' : 'Off.');
    }

    /**
     * Caches that make .env edits do nothing.
     *
     * Locally this is a trap rather than an optimisation: config:cache
     * freezes .env into a file, so you edit a value, reload, see no change,
     * and spend twenty minutes doubting the code.
     */
    private function configCache(): ?array
    {
        if (app()->environment('production')) {
            return null;
        }

        $cached = array_filter([
            'config' => file_exists(base_path('bootstrap/cache/config.php')),
            'routes' => file_exists(base_path('bootstrap/cache/routes-v7.php')),
        ]);

        if ($cached) {
            return $this->make('env.cache', 'Environment', 'Config cache', self::WARN,
                'Cached locally: '.implode(', ', array_keys($cached)),
                fix: 'Changes to .env and to routes will appear to have no effect until this is cleared.',
                command: 'php artisan optimize:clear');
        }

        return $this->make('env.cache', 'Environment', 'Config cache', self::OK,
            'Not cached — edits take effect immediately.');
    }

    /* ════════════════════════════ Database ════════════════════════════ */

    private function database(): array
    {
        try {
            DB::connection()->getPdo();

            return $this->make('db.connection', 'Database', 'Connection', self::OK,
                DB::connection()->getDriverName().' · '.DB::connection()->getDatabaseName());
        } catch (Throwable $e) {
            return $this->make('db.connection', 'Database', 'Connection', self::FAIL,
                $e->getMessage(),
                fix: 'Check the DB_ credentials in .env and that the server is running.');
        }
    }

    /**
     * Migrations that exist as files but were never applied.
     *
     * The single most common cause of entries on the Errors screen: code
     * that expects a column the database has not got.
     */
    private function pendingMigrations(): array
    {
        if (! Schema::hasTable('migrations')) {
            return $this->make('db.migrations', 'Database', 'Migrations', self::FAIL,
                'The migrations table does not exist.',
                command: 'php artisan migrate');
        }

        $files = collect(glob(database_path('migrations/*.php')))
            ->map(fn ($path) => basename($path, '.php'));

        $pending = $files->diff(DB::table('migrations')->pluck('migration'));

        if ($pending->isNotEmpty()) {
            return $this->make('db.migrations', 'Database', 'Migrations', self::FAIL,
                $pending->count().' pending: '.$pending->take(3)->implode(', ').($pending->count() > 3 ? '…' : ''),
                fix: 'The code expects tables or columns the database does not have yet.',
                command: 'php artisan migrate');
        }

        return $this->make('db.migrations', 'Database', 'Migrations', self::OK,
            $files->count().' applied, none pending.');
    }

    /* ═══════════════════════════ Background ═══════════════════════════ */

    private function queueDriver(): array
    {
        if (config('queue.default') === 'sync') {
            return $this->make('queue.driver', 'Background', 'Queue driver', self::FAIL,
                'Driver is "sync".',
                fix: 'Audio conversion would run inside the web request and time out on anything but a tiny file. Set QUEUE_CONNECTION=database.',
                route: 'admin.queue');
        }

        return $this->make('queue.driver', 'Background', 'Queue driver', self::OK,
            config('queue.default').' — worker health is on the Queue screen.',
            route: 'admin.queue');
    }

    /**
     * Is the scheduler running at all?
     *
     * Nothing else can tell you. When schedule:work stops, the analytics
     * rollup, the log pruning and the weekly digest all just stop happening
     * — no error, no failed job, no trace anywhere. The scheduler stamps a
     * cache key every five minutes; a stale stamp is the only evidence
     * there is.
     */
    private function scheduler(): array
    {
        $stamp = Cache::get(self::SCHEDULER_STAMP);

        if (! $stamp) {
            return $this->make('schedule.alive', 'Background', 'Scheduler', self::WARN,
                'Never seen running.',
                fix: 'Without it the analytics rollup, the log pruning and the digest never run — silently.',
                command: 'php artisan schedule:work');
        }

        $minutes = (int) round((time() - (int) $stamp) / 60);

        if ($minutes > 15) {
            return $this->make('schedule.alive', 'Background', 'Scheduler', self::FAIL,
                "Last ran {$minutes} minutes ago.",
                fix: 'It stamps this every five minutes. Scheduled work has stopped.',
                command: 'php artisan schedule:work');
        }

        return $this->make('schedule.alive', 'Background', 'Scheduler', self::OK,
            'Last ran '.($minutes < 1 ? 'less than a minute' : "{$minutes} minutes").' ago.');
    }

    /* ══════════════════════════════ Mail ══════════════════════════════ */

    private function mailDriver(): array
    {
        $driver = config('mail.default');

        if (in_array($driver, ['log', 'array'], true)) {
            return $this->make('mail.driver', 'Mail', 'Delivery', self::WARN,
                "Driver is \"{$driver}\" — nothing is actually sent.",
                fix: 'Correct while developing. If this is still set when the site is public, no contributor is ever told their sound was approved and no password reset ever arrives.');
        }

        return $this->make('mail.driver', 'Mail', 'Delivery', self::OK, $driver);
    }

    /* ═════════════════════════════ Media ═════════════════════════════ */

    private function ffmpeg(): array
    {
        $available = app(AudioProcessor::class)->isAvailable();

        return $available
            ? $this->make('media.ffmpeg', 'Media', 'ffmpeg', self::OK, 'Available.')
            : $this->make('media.ffmpeg', 'Media', 'ffmpeg', self::FAIL,
                'Not found on PATH.',
                fix: 'Every upload will fail to convert. The queue worker needs it on ITS path too, which is not always the same one.',
                command: 'brew install ffmpeg');
    }

    private function uploadLimits(): ?array
    {
        if (! class_exists(UploadLimits::class) || ! method_exists(UploadLimits::class, 'tooLowForAudio')) {
            return null;
        }

        if (UploadLimits::tooLowForAudio()) {
            return $this->make('media.upload', 'Media', 'Upload limits', self::WARN,
                'PHP limits are below what a WAV master needs.',
                fix: 'Raise upload_max_filesize and post_max_size. The Bulk upload screen prints the exact php.ini path.',
                route: 'admin.bulk-upload');
        }

        return $this->make('media.upload', 'Media', 'Upload limits', self::OK, 'Sufficient for audio masters.');
    }

    /* ═════════════════════════════ Search ═════════════════════════════ */

    private function searchEngine(): array
    {
        $driver = config('scout.driver');

        if ($driver !== 'meilisearch') {
            return $this->make('search.engine', 'Search', 'Engine', self::WARN,
                "Scout driver is \"{$driver}\".",
                fix: 'Search falls back to plain SQL: no typo tolerance, and it degrades as the catalogue grows.');
        }

        try {
            $host = rtrim((string) config('scout.meilisearch.host'), '/');
            $response = Http::timeout(3)->get("{$host}/health");

            return $response->successful()
                ? $this->make('search.engine', 'Search', 'Engine', self::OK, $host)
                : $this->make('search.engine', 'Search', 'Engine', self::FAIL,
                    'Responded '.$response->status(),
                    route: 'admin.search');
        } catch (Throwable) {
            return $this->make('search.engine', 'Search', 'Engine', self::FAIL,
                'Unreachable.',
                fix: 'Search degrades rather than crashing, so visitors get worse results and nothing is logged.',
                command: 'cd ~/meilisearch && ./meilisearch');
        }
    }

    /* ════════════════════════ Storage & security ════════════════════════ */

    private function publicLink(): array
    {
        return is_link(public_path('storage'))
            ? $this->make('storage.link', 'Storage', 'Public symlink', self::OK, 'public/storage')
            : $this->make('storage.link', 'Storage', 'Public symlink', self::FAIL,
                'Missing.',
                fix: 'Previews and images will 404 even though the files exist.',
                command: 'php artisan storage:link');
    }

    private function writablePaths(): array
    {
        $unwritable = array_filter([
            'storage/framework' => storage_path('framework'),
            'storage/logs' => storage_path('logs'),
            'bootstrap/cache' => base_path('bootstrap/cache'),
        ], fn ($path) => ! is_writable($path));

        if ($unwritable) {
            return $this->make('storage.writable', 'Storage', 'Writable paths', self::FAIL,
                'Not writable: '.implode(', ', array_keys($unwritable)),
                fix: 'Sessions, compiled views and the log file all need these.');
        }

        return $this->make('storage.writable', 'Storage', 'Writable paths', self::OK, 'All writable.');
    }

    /**
     * .env inside the web root.
     *
     * If it is reachable over HTTP, every credential the application has is
     * public. Rare, but the consequence is total, so it is worth one
     * file_exists per visit to this screen.
     */
    private function exposedEnv(): array
    {
        return file_exists(public_path('.env'))
            ? $this->make('security.env', 'Security', 'Environment file', self::FAIL,
                'There is a .env inside public/.',
                fix: 'Anyone can read every credential over HTTP. Move it out of the web root immediately.')
            : $this->make('security.env', 'Security', 'Environment file', self::OK, 'Outside the web root.');
    }

    private function phpExtensions(): array
    {
        $required = ['pdo_mysql', 'mbstring', 'openssl', 'fileinfo', 'curl', 'zip', 'intl'];
        $missing = array_values(array_filter($required, fn ($ext) => ! extension_loaded($ext)));

        if ($missing) {
            return $this->make('php.extensions', 'Environment', 'PHP extensions', self::WARN,
                'Missing: '.implode(', ', $missing),
                fix: 'zip is needed for pack downloads, intl for locale-aware formatting, fileinfo for upload type detection.');
        }

        return $this->make('php.extensions', 'Environment', 'PHP extensions', self::OK,
            PHP_VERSION.' · all required extensions loaded.');
    }

    /* ═════════════════════════════ Helpers ═════════════════════════════ */

    private function make(
        string $key,
        string $group,
        string $label,
        string $status,
        string $detail,
        ?string $fix = null,
        ?string $command = null,
        ?string $route = null,
    ): array {
        return compact('key', 'group', 'label', 'status', 'detail', 'fix', 'command', 'route');
    }

    private function ago(int $timestamp): string
    {
        $minutes = (int) round((time() - $timestamp) / 60);

        return match (true) {
            $minutes < 1 => 'moments ago',
            $minutes < 60 => "{$minutes} minutes ago",
            $minutes < 1440 => round($minutes / 60).' hours ago',
            default => round($minutes / 1440).' days ago',
        };
    }
}
