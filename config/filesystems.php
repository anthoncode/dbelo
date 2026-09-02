<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim((string) env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        /*
        | Master audio files and paid downloads. Deliberately outside public/
        | so nobody can bypass the quota check by guessing a URL. Served only
        | through a controller that verifies session, plan and daily quota.
        */
        'sounds_private' => [
            'driver' => 'local',
            'root' => storage_path('app/sounds'),
            'throw' => false,
            'report' => false,
        ],

        /*
        | Where backups land.
        |
        | Its own disk so the destination is one line to change: today it is
        | this machine, and the day R2 is on it becomes 'r2' in config/backup.php
        | without touching anything else.
        |
        | A backup on the same disk as the database is not a backup — it is an
        | undo button. A useful one, because "I broke the data with a bad
        | query" is the most likely accident while building, but it survives
        | nothing that happens to the machine.
        */
        'backups' => [
            'driver' => 'local',
            'root' => storage_path('app/backups'),
            'throw' => false,
            'report' => false,
        ],

        /*
        | Cloudflare R2. Speaks the S3 protocol, so it uses the same driver
        | with a custom endpoint. Chosen over S3 because R2 does not charge
        | for egress, and serving audio is almost entirely egress.
        */
        'r2' => [
            'driver' => 's3',
            'key' => env('R2_ACCESS_KEY_ID'),
            'secret' => env('R2_SECRET_ACCESS_KEY'),
            'region' => 'auto',
            'bucket' => env('R2_BUCKET'),
            'endpoint' => env('R2_ENDPOINT'),
            'url' => env('R2_PUBLIC_URL'),
            'use_path_style_endpoint' => true,
            'throw' => false,
            'report' => false,
        ],

        /*
        | Backblaze B2. Also S3-compatible — same driver, different endpoint.
        |
        | Cheaper per stored GB than R2, and egress is free up to three times
        | what you store, then unlimited through Cloudflare. It is here so the
        | choice between the two stays a change of environment variables
        | rather than a change of code: the point of this file is that the
        | application talks to ONE protocol and the provider is a detail.
        */
        'b2' => [
            'driver' => 's3',
            'key' => env('B2_ACCESS_KEY_ID'),
            'secret' => env('B2_SECRET_ACCESS_KEY'),
            'region' => env('B2_REGION', 'us-west-004'),
            'bucket' => env('B2_BUCKET'),
            'endpoint' => env('B2_ENDPOINT'),
            'url' => env('B2_PUBLIC_URL'),
            'use_path_style_endpoint' => true,
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
