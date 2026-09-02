<?php

/*
|--------------------------------------------------------------------------
| Backups
|--------------------------------------------------------------------------
|
| A PARTIAL config. Spatie merges this over the package's own defaults at
| the top level, so only the keys that need a decision are here — and the
| ones that do not stay in the package, where they get maintained.
|
| A top-level key defined here REPLACES the package's, it does not merge
| into it, so `source` and `destination` are written out in full.
|
| The decision this file exists to record: THE AUDIO IS NOT IN THE BACKUP.
| Out of the box the package would sweep up base_path(), which means every
| WAV master on the machine, in a zip, every night. See the note under
| `files` for why that is the wrong shape for audio.
|
*/

return [

    'backup' => [

        'name' => 'dbelo',

        'source' => [

            'files' => [
                /*
                 * Nothing. Deliberately.
                 *
                 * The code is in git. The audio is measured in tens or
                 * hundreds of gigabytes, is derived-or-original (previews and
                 * MP3s can be rebuilt with ffmpeg from the masters), and
                 * belongs on object storage that replicates it — not in a
                 * nightly zip that would fill the disk it is stored on.
                 *
                 * Audio leaves this machine through Admin → Backups, on
                 * demand, in bounded selections a person chooses. That is a
                 * different job with a different shape, and pretending it is
                 * the same job is how backups quietly stop running.
                 */
                'include' => [],

                'exclude' => [
                    base_path('vendor'),
                    base_path('node_modules'),
                    storage_path('app/sounds'),
                    storage_path('app/backups'),
                ],

                'follow_links' => false,
                'ignore_unreadable_directories' => false,
                'relative_path' => null,
            ],

            /*
             * The part that cannot be recreated from anything.
             *
             * Small — this database holds metadata, not media — so a full
             * dump every night costs nothing and is the whole story.
             */
            'databases' => [
                'mysql',
            ],
        ],

        /*
         * Compressed with gzip. A SQL dump is text and compresses roughly
         * ten to one, and this is the difference between a directory of
         * dumps you keep for months and one you start deleting.
         */
        'database_dump_compressor' => Spatie\DbDumper\Compressors\GzipCompressor::class,

        'destination' => [
            'compression_method' => ZipArchive::CM_DEFAULT,
            'compression_level' => 9,

            'filename_prefix' => 'dbelo-',

            /*
             * One disk today, and it is on the same machine as the database.
             * Admin → Backups says so in amber rather than pretending
             * otherwise. Add 'r2' here the day the bucket exists — the
             * package writes to every disk listed.
             */
            'disks' => [
                'backups',
            ],
        ],

        'temporary_directory' => storage_path('app/backup-temp'),

        /*
         * Not encrypted, and that is a decision rather than an oversight.
         *
         * An encrypted backup is worth exactly as much as your ability to
         * find the password on the worst day of the year. While the backups
         * sit on your own machine, the encryption adds a way to lose the
         * data and no protection you did not already have. Set both of these
         * the day backups start leaving the building.
         */
        'password' => env('BACKUP_ARCHIVE_PASSWORD'),
        'encryption' => 'default',
    ],

    /*
     * How long copies are kept.
     *
     * Thinning rather than a single cut-off: dense for the last week, when
     * "undo what I did an hour ago" is the actual use, and sparse beyond
     * that, where the question is "what did this look like in June".
     */
    'cleanup' => [
        'strategy' => Spatie\Backup\Tasks\Cleanup\Strategies\DefaultStrategy::class,

        'default_strategy' => [
            'keep_all_backups_for_days' => 7,
            'keep_daily_backups_for_days' => 30,
            'keep_weekly_backups_for_weeks' => 8,
            'keep_monthly_backups_for_months' => 6,
            'keep_yearly_backups_for_years' => 2,
            'delete_oldest_backups_when_using_more_megabytes_than' => 5000,
        ],
    ],
];
