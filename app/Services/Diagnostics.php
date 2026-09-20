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
            'siteStatus', 'settingsWired',
            'appKey', 'appDebug', 'configCache',
            'database', 'pendingMigrations',
            'counterDrift', 'soundsWithoutFiles', 'orphanRows', 'tableSizes',
            'queueDriver', 'scheduler',
            'mailDriver',
            'backupAge',
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

    /**
     * Is the compiled stylesheet older than the code that needs it?
     *
     * Public so the admin layout can ask on every page, not just this
     * screen. The check has existed here since the day it was written and it
     * has still cost two separate afternoons — because it lives on a screen
     * you only open once you already suspect something, and this failure
     * does not feel like a broken build. It feels like one control that will
     * not work: the switch has no colour, the panel has no padding, and
     * everything else on the page looks fine.
     *
     * Reusing buildFreshness() rather than repeating its logic. Its source
     * scan is cached for a minute, which is what makes this affordable on
     * every admin request.
     */
    public function assetsBuild(): array
    {
        try {
            return $this->buildFreshness();
        } catch (Throwable) {
            // Unknown is reported as fine. A banner that appears because a
            // check misfired is worse than no banner: the first thing it
            // teaches is that it can be ignored.
            return $this->make('build.fresh', 'Front-end', 'Asset build', self::OK, '');
        }
    }

    public function assetsStale(): bool
    {
        return $this->assetsBuild()['status'] !== self::OK;
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

        /*
         * SEVERITY BY AGE, not a flat FAIL.
         *
         * A flat FAIL was wrong, and wrong in the way this project keeps
         * warning about: during active work the build goes behind every time
         * a file is written, so the alarm was on almost permanently — and an
         * alarm that is always on is one nobody reads, which is how the real
         * one gets missed. It had already reached that state here.
         *
         * Under a day is ordinary drift between one build and the next. Over
         * a day means somebody has been looking at a stale site for a day
         * without noticing, which is the failure this check exists for.
         */
        $status = $behind >= 1440 ? self::FAIL : self::WARN;

        $unit = fn (int $n, string $word) => $n.' '.\Illuminate\Support\Str::plural($word, $n);

        $age = match (true) {
            $behind >= 1440 => $unit((int) round($behind / 1440), 'day'),
            $behind >= 60 => $unit((int) round($behind / 60), 'hour'),
            default => $unit($behind, 'minute'),
        };

        return $this->make('build.fresh', 'Front-end', 'Asset build', $status,
            "The stylesheet is {$age} older than the newest source file.",
            fix: 'Any CSS class written since then does not exist in the build, so those elements render unstyled — with no error anywhere. Vite is not watching: start it and this stops happening after every change.',
            command: './dev.sh');
    }

    /**
     * How far the build is behind, in minutes. 0 when it is not.
     *
     * Public so the banner can choose its own volume. The check above
     * decides whether this is a problem; the banner decides how loudly to
     * say so, and those are different questions.
     */
    public function assetsBehind(): int
    {
        try {
            if (file_exists(public_path('hot'))) {
                return 0;
            }

            $manifest = public_path('build/manifest.json');

            if (! file_exists($manifest)) {
                return PHP_INT_MAX;
            }

            $behind = $this->newestSourceTime() - filemtime($manifest);

            return $behind > 0 ? (int) round($behind / 60) : 0;
        } catch (Throwable) {
            return 0;
        }
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

    /* ═══════════════════════════ Data health ═══════════════════════════
     *
     * A different kind of wrong from everything above.
     *
     * The checks up to here ask whether the environment matches the code:
     * is the database reachable, is its shape current, is the queue being
     * consumed. These ask whether the DATA INSIDE IT still agrees with
     * itself — a counter that drifted, a published sound whose file is
     * gone, a row pointing at something that was deleted.
     *
     * ── THEY REPORT. THEY DO NOT REPAIR. ─────────────────────────────────
     *
     * There is no button here and that is the design. A button that fixes
     * data is a button that DELETES data, and "orphan" is a word that means
     * whatever the query defining it says it means — get that query subtly
     * wrong and the button removes rows that were fine, with no undo short
     * of restoring a backup. The cost of being told a number and acting on
     * it deliberately is a few minutes; the cost of the other mistake is
     * unbounded.
     *
     * So each check prints what it found and the command that would address
     * it, and a person decides. Counters are the one thing genuinely safe to
     * recompute — the correct value can always be derived again — and even
     * that is offered as a command rather than a click.
     *
     * ── EVERY QUERY HERE IS AN AGGREGATE ─────────────────────────────────
     *
     * No row is ever loaded. These run on a screen somebody opens when they
     * already suspect a problem, which is the worst moment to make the
     * database do real work, so each is a single COUNT or GROUP BY and the
     * whole section is cached for five minutes.
     * ═══════════════════════════════════════════════════════════════════ */

    /**
     * Denormalised counters that no longer match what they count.
     *
     * downloads_count and favorites_count are columns kept in step by hand.
     * Anything that removes a row without going through the code that
     * decrements them — a manual DELETE, a cascade, a restored backup —
     * leaves the number lying, and nothing anywhere notices. The catalogue
     * then sorts "most downloaded" by a figure that is quietly wrong.
     *
     * plays_count is NOT checked, and cannot be: nothing records individual
     * plays, so there is no truth to compare it against. Worth knowing
     * rather than worth fixing.
     */
    private function counterDrift(): ?array
    {
        if (! Schema::hasTable('sounds') || ! Schema::hasTable('downloads')) {
            return null;
        }

        $drift = Cache::remember('diagnostics.counter.drift', now()->addMinutes(5), function () {
            $downloads = DB::table('sounds')
                ->whereNull('deleted_at')
                ->whereRaw('downloads_count <> (select count(*) from downloads where downloads.sound_id = sounds.id)')
                ->count();

            $favourites = Schema::hasTable('favorites')
                ? DB::table('sounds')
                    ->whereNull('deleted_at')
                    ->whereRaw('favorites_count <> (select count(*) from favorites where favorites.sound_id = sounds.id)')
                    ->count()
                : 0;

            return ['downloads' => $downloads, 'favourites' => $favourites];
        });

        $total = $drift['downloads'] + $drift['favourites'];

        if ($total === 0) {
            return $this->make('data.counters', 'Data health', 'Counters', self::OK,
                'Download and favourite counts match their tables.');
        }

        return $this->make('data.counters', 'Data health', 'Counters', self::WARN,
            $drift['downloads'].' sounds with a wrong download count, '
                .$drift['favourites'].' with a wrong favourite count.',
            fix: 'The catalogue sorts and displays these numbers, so they are visibly wrong to visitors. Recomputing is safe — the correct value is derived from the rows themselves.',
            command: 'php artisan sounds:recount');
    }

    /**
     * Sounds that are live on the site with nothing to play or download.
     *
     * The worst failure in the catalogue and the quietest: the page renders,
     * the title is there, the waveform may even draw from stored peaks — and
     * the player is silent, or Download returns nothing. No exception is
     * thrown, so the Errors screen stays empty and the only person who finds
     * out is a visitor who leaves.
     */
    private function soundsWithoutFiles(): ?array
    {
        if (! Schema::hasTable('sounds') || ! Schema::hasTable('sound_files')) {
            return null;
        }

        $broken = Cache::remember('diagnostics.sounds.fileless', now()->addMinutes(5), fn () => [
            'preview' => DB::table('sounds')
                ->where('status', 'published')
                ->whereNull('deleted_at')
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('sound_files')
                    ->whereColumn('sound_files.sound_id', 'sounds.id')
                    ->where('purpose', 'preview'))
                ->count(),

            'download' => DB::table('sounds')
                ->where('status', 'published')
                ->whereNull('deleted_at')
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('sound_files')
                    ->whereColumn('sound_files.sound_id', 'sounds.id')
                    ->where('purpose', 'download'))
                ->count(),
        ]);

        if ($broken['preview'] === 0 && $broken['download'] === 0) {
            return $this->make('data.files', 'Data health', 'Published sounds', self::OK,
                'Every published sound has a preview and a download.');
        }

        return $this->make('data.files', 'Data health', 'Published sounds', self::FAIL,
            $broken['preview'].' published with no preview, '.$broken['download'].' with no download file.',
            fix: 'These pages are live and silent. Either the conversion never finished or the files were removed. Re-running the processing job rebuilds both from the master.',
            route: 'moderate');
    }

    /**
     * Rows pointing at a sound that no longer exists.
     *
     * ── THIS SHOULD ALWAYS READ ZERO, AND THAT IS THE POINT ──────────────
     *
     * sound_files, downloads, favorites and the tag pivot all declare
     * `constrained()->cascadeOnDelete()`, so the database itself removes
     * these the instant a sound is force-deleted. In a healthy install the
     * number cannot be anything but zero.
     *
     * Which makes a non-zero answer worth a great deal more than a tidy-up
     * job: it means a foreign key is missing or was never enforced. That
     * happens for real — a table rebuilt by hand, an import that dropped
     * constraints, SQLite running without foreign_keys ON, a restore from a
     * dump taken with checks disabled. The orphan rows are the symptom; the
     * missing constraint is the problem, and it is silently allowing worse
     * things than these rows.
     *
     * So this is not an orphan sweeper. It is a check that the cascades are
     * still doing their job — which is why it counts and offers nothing that
     * deletes. Clearing the rows would hide the only evidence that a
     * constraint is gone.
     */
    private function orphanRows(): ?array
    {
        if (! Schema::hasTable('sound_files')) {
            return null;
        }

        $orphans = Cache::remember('diagnostics.orphans', now()->addMinutes(5), function () {
            $out = [];

            // A file row whose sound was force-deleted. Soft-deleted sounds
            // keep their files on purpose — the sound can come back — so
            // deleted_at is not the test here; existence is.
            $out['sound_files'] = DB::table('sound_files')
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('sounds')
                    ->whereColumn('sounds.id', 'sound_files.sound_id'))
                ->count();

            if (Schema::hasTable('sound_tag')) {
                $out['sound_tag'] = DB::table('sound_tag')
                    ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('sounds')
                        ->whereColumn('sounds.id', 'sound_tag.sound_id'))
                    ->count();
            }

            foreach (['downloads', 'favorites'] as $table) {
                if (Schema::hasTable($table)) {
                    $out[$table] = DB::table($table)
                        ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('sounds')
                            ->whereColumn('sounds.id', $table.'.sound_id'))
                        ->count();
                }
            }

            return array_filter($out);
        });

        if ($orphans === []) {
            return $this->make('data.orphans', 'Data health', 'Orphan rows', self::OK,
                'No rows pointing at a deleted sound.');
        }

        $parts = [];

        foreach ($orphans as $table => $count) {
            $parts[] = $count.' in '.$table;
        }

        return $this->make('data.orphans', 'Data health', 'Orphan rows', self::WARN,
            implode(', ', $parts).'.',
            fix: 'These tables cascade on delete, so this should be impossible — a foreign key is probably missing or unenforced. Check the constraints before clearing anything: the rows are the evidence, and a missing constraint allows worse than a few stale rows.');
    }

    /**
     * What the database actually weighs, biggest table first.
     *
     * Informational, never a failure. It is here because a retention rule is
     * impossible to judge in the abstract: "should search_daily be kept for
     * a year" is unanswerable until you can see it is the second largest
     * table on the system.
     *
     * MySQL only. The numbers are the engine's own estimate and can be off
     * by a chunk on InnoDB — fine for "which one is growing", useless for
     * anything that needs a precise figure.
     */
    private function tableSizes(): ?array
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return null;
        }

        $top = Cache::remember('diagnostics.table.sizes', now()->addMinutes(30), function () {
            return DB::select(
                'select table_name as name,
                        table_rows as rows_estimate,
                        round((data_length + index_length) / 1024 / 1024, 1) as mb
                 from information_schema.tables
                 where table_schema = ?
                 order by (data_length + index_length) desc
                 limit 5',
                [DB::connection()->getDatabaseName()],
            );
        });

        if ($top === []) {
            return null;
        }

        $total = Cache::remember('diagnostics.db.size', now()->addMinutes(30), function () {
            return (float) (DB::selectOne(
                'select round(sum(data_length + index_length) / 1024 / 1024, 1) as mb
                 from information_schema.tables where table_schema = ?',
                [DB::connection()->getDatabaseName()],
            )?->mb ?? 0);
        });

        $parts = array_map(
            fn ($row) => $row->name.' '.$row->mb.' MB',
            array_slice($top, 0, 3),
        );

        return $this->make('data.size', 'Data health', 'Size', self::OK,
            $total.' MB total · biggest: '.implode(', ', $parts),
            fix: 'Retention rules are in each model\'s prunable(). `php artisan model:prune --pretend` says what the nightly clean-up would remove without removing it.');
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

    /**
     * How old the newest backup is.
     *
     * The age, not the existence. A directory full of backups from three
     * weeks ago is worse than an empty one, because it answers "am I covered"
     * with a yes. This is also the only check here that can catch a
     * scheduler that stopped WITHOUT the heartbeat noticing — the stamp is
     * written by a closure, the backup by a command, and they fail
     * separately.
     */
    private function backupAge(): array
    {
        if (! class_exists(\Spatie\Backup\BackupServiceProvider::class)) {
            return $this->make('backup.age', 'Backups', 'Database backup', self::WARN,
                'The backup package is not installed.',
                fix: 'Nothing is being copied anywhere.',
                command: 'composer require spatie/laravel-backup',
                route: 'admin.backups');
        }

        try {
            $disk = \Illuminate\Support\Facades\Storage::disk('backups');

            $newest = collect($disk->allFiles())
                ->filter(fn ($path) => str_ends_with($path, '.zip'))
                ->map(fn ($path) => $disk->lastModified($path))
                ->max();
        } catch (Throwable) {
            $newest = null;
        }

        if (! $newest) {
            return $this->make('backup.age', 'Backups', 'Database backup', self::FAIL,
                'No backup has ever been made.',
                fix: 'The nightly job runs at 02:40. Until it has, there is nothing to restore from.',
                route: 'admin.backups');
        }

        $hours = (int) round((time() - $newest) / 3600);

        if ($hours > 48) {
            return $this->make('backup.age', 'Backups', 'Database backup', self::FAIL,
                "Newest copy is {$hours} hours old.",
                fix: 'The nightly job has not run for more than two days. Check the scheduler.',
                route: 'admin.backups');
        }

        if ($hours > 26) {
            return $this->make('backup.age', 'Backups', 'Database backup', self::WARN,
                "Newest copy is {$hours} hours old.",
                fix: 'A night has been missed. One is not a problem; two means the schedule is not running.',
                route: 'admin.backups');
        }

        return $this->make('backup.age', 'Backups', 'Database backup', self::OK,
            $hours < 1 ? 'Taken less than an hour ago.' : "Taken {$hours} hours ago.");
    }

    /* ═════════════════════════════ Helpers ═════════════════════════════ */

    /**
     * Is the site open to anybody but you?
     *
     * Belongs on this screen precisely because it is invisible from the
     * inside: admins bypass the closed door, so a site left in "coming soon"
     * after launch looks completely normal to the one person who could fix
     * it. Nothing is thrown, nothing is logged, and every visitor gets a
     * placeholder. That is the exact shape of failure this screen is for.
     */
    private function siteStatus(): array
    {
        $status = \App\Support\SiteStatus::current();

        if ($status === \App\Support\SiteStatus::LIVE) {
            return $this->make('site.status', 'Site', 'Public access', self::OK,
                'Open to everyone.');
        }

        return $this->make('site.status', 'Site', 'Public access', self::WARN,
            $status === \App\Support\SiteStatus::SOON
                ? 'Closed — visitors get the coming-soon page.'
                : 'Closed — visitors get the maintenance page.',
            fix: 'Deliberate before launch, and invisible afterwards: you are an admin, so you see the real site either way. Nothing else will ever tell you this is still on.',
            route: 'admin.settings.general');
    }

    /**
     * Does every setting actually reach the site?
     *
     * The check that exists because of a real afternoon lost to it. A
     * setting has two halves: AppServiceProvider::OVERLAY lays the stored
     * value over a config key, and somewhere a view has to call config() on
     * that key. Only the first half is code anybody remembers to write. Miss
     * the second and the field saves perfectly, the activity log records the
     * change, and the site keeps printing the sentence hard-coded in a
     * template — with no error anywhere, in the panel or the log.
     *
     * From the outside that is indistinguishable from a broken save, which
     * is why it costs an afternoon rather than a minute.
     *
     * The scan is a plain substring search over app/ and resources/views/.
     * Crude on purpose: config() is called with a literal string in every
     * one of those files, and a parser that understood PHP would be a
     * hundred times the code for the same answer.
     */
    private function settingsWired(): array
    {
        $overlay = \App\Providers\AppServiceProvider::OVERLAY;
        $declared = \App\Providers\AppServiceProvider::NOT_YET_CONSUMED;

        if ($overlay === []) {
            return $this->make('settings.wired', 'Site', 'Settings reach the site', self::OK,
                'Nothing to check.');
        }

        /*
         * Keys Laravel itself consumes, in files this scan does not read.
         * Absence proves nothing for these, so their presence in a setting's
         * target list is enough to call that setting wired — which is how
         * site.name passes: no view reads dbelo.site.name, every view reads
         * app.name, and the overlay writes both.
         */
        $framework = ['app.name', 'app.timezone', 'mail.from.name', 'mail.from.address'];

        $haystack = $this->sourceText();

        $orphans = [];
        $waiting = [];
        $stale = [];

        foreach ($overlay as $setting => $targets) {
            /*
             * The setting key itself counts as a needle, not only its config
             * targets. Plenty of code reads a setting through
             * Setting::read('site.status') rather than through config(), and
             * that is a real reader — it just spells the key differently.
             */
            $wired = $this->readsKey($haystack, $setting);

            foreach ($targets as $target) {
                if ($wired) {
                    break;
                }

                if (in_array($target, $framework, true) || $this->readsKey($haystack, $target)) {
                    $wired = true;
                }
            }

            if (array_key_exists($setting, $declared)) {
                // Declared as not-yet-used. If a reader has appeared since,
                // the declaration is stale and the field is still wearing a
                // "Not used yet" chip that has become a lie.
                $wired ? $stale[] = $setting : $waiting[] = $setting;

                continue;
            }

            if (! $wired) {
                $orphans[] = $setting;
            }
        }

        if ($orphans !== []) {
            return $this->make('settings.wired', 'Site', 'Settings reach the site', self::WARN,
                'Saved but never read: '.implode(', ', $orphans).'.',
                fix: 'These change nothing on the site. Either a view is missing its config() call, or the setting belongs to a feature not built yet — in which case list it in AppServiceProvider::NOT_YET_CONSUMED so the field says so instead of pretending.',
                route: 'admin.settings.general');
        }

        if ($stale !== []) {
            return $this->make('settings.wired', 'Site', 'Settings reach the site', self::WARN,
                implode(', ', $stale).' is marked not-yet-used, but something reads it now.',
                fix: 'Remove it from AppServiceProvider::NOT_YET_CONSUMED, or its field keeps telling the operator it does nothing.',
                route: 'admin.settings.general');
        }

        $count = count($overlay) - count($waiting);

        return $this->make('settings.wired', 'Site', 'Settings reach the site', self::OK,
            $waiting === []
                ? "{$count} settings, each read by at least one view."
                : "{$count} settings reach the site; ".count($waiting).' waiting on a feature ('.implode(', ', $waiting).').');
    }

    /**
     * Is this key read anywhere?
     *
     * Matched on a key BOUNDARY, not as a loose substring. Without the
     * lookahead, "dbelo.site" would be found inside "dbelo.site.description"
     * and every sibling setting would look wired because one of them was.
     *
     * A view may legitimately read the whole parent array —
     * config('dbelo.legal') rather than config('dbelo.legal.support_email') —
     * so a complete parent counts as a reader. One level up only: reducing
     * to a single segment would match half the codebase.
     */
    private function readsKey(string $haystack, string $key): bool
    {
        if (preg_match('/'.preg_quote($key, '/').'(?![\w.])/', $haystack)) {
            return true;
        }

        $parent = implode('.', array_slice(explode('.', $key), 0, -1));

        return substr_count($parent, '.') >= 1
            && preg_match('/'.preg_quote($parent, '/').'(?![\w.])/', $haystack) === 1;
    }

    /**
     * Every PHP and Blade source file, concatenated.
     *
     * Crude on purpose: both config() and Setting::read() are called with a
     * literal string in all of them, and a parser that understood PHP would
     * be a hundred times the code for the same answer.
     *
     * TWO PLACES ARE SKIPPED, and skipping them is what makes the check mean
     * anything:
     *
     *   AppServiceProvider holds the overlay map itself, so every key
     *   appears there by definition.
     *
     *   The Settings screens are where keys are WRITTEN. A field that saves
     *   a value nothing reads still mentions its own key, and counting that
     *   as a reader would make the check pass in exactly the case it exists
     *   to catch.
     */
    private function sourceText(): string
    {
        $text = '';
        $skip = ['AppServiceProvider.php'];

        foreach ([app_path(), resource_path('views')] as $root) {
            if (! is_dir($root)) {
                continue;
            }

            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($files as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $path = $file->getPathname();

                if (str_contains($path, '/pages/admin/settings/')) {
                    continue;
                }

                foreach ($skip as $name) {
                    if (str_ends_with($path, $name)) {
                        continue 2;
                    }
                }

                $text .= file_get_contents($path);
            }
        }

        return $text;
    }

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
