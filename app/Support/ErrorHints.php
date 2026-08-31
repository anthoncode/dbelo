<?php

namespace App\Support;

use App\Models\ErrorGroup;
use Illuminate\Support\Str;

/**
 * What an error MEANS, and what to do about it.
 *
 * A stack trace tells you where the program stopped. It does not tell you
 * why, and for most of the errors a Laravel application actually produces
 * the "why" is one of about twenty stories that repeat forever. Writing
 * those twenty down turns the screen from a place where you find out
 * something broke into a place where you find out what to do — which is the
 * difference between a log and a tool.
 *
 * Several of the entries below are lessons this project paid for in real
 * time. They are here so the next person does not have to pay again.
 *
 * Matching is most-specific-first: a message pattern beats a class, because
 * "QueryException" alone could be six different problems and the message is
 * what separates them.
 */
class ErrorHints
{
    /**
     * Matched on the MESSAGE. Checked before the class list.
     *
     * @var array<int, array{match: string, title: string, what: string, fix: array<int, string>, route?: string, routeLabel?: string}>
     */
    private const BY_MESSAGE = [
        [
            'match' => 'cannot be called statically',
            'title' => 'A model method is shadowing the query builder',
            'what' => 'Eloquent forwards unknown static calls to the query builder through __callStatic — but a real public method on the model wins that race. So defining a method with a name the builder already owns (where, find, create, select, count, with, limit…) silently turns every static call of that name into a fatal error, usually far away from the model you edited.',
            'fix' => [
                'Read the class and method named in the message.',
                'Rename that method to something the query builder does not own — location(), describe(), forHumans().',
                'Update the callers. There are usually only one or two, because the method is new.',
            ],
        ],
        [
            'match' => 'Base table or view not found',
            'title' => 'A migration has not been run',
            'what' => 'The code is querying a table the database does not have. Almost always a migration that exists in the repository but was never applied here — after pulling changes, or after a fresh clone.',
            'fix' => [
                'php artisan migrate',
                'If it still fails, check the table name in the message actually exists in database/migrations.',
            ],
        ],
        [
            'match' => 'Unknown column',
            'title' => 'The table exists but the column does not',
            'what' => 'A migration that adds this column has not run, or a column was renamed in the code but not in the database. The half-applied state is the usual cause: a migration failed partway and was not rolled back.',
            'fix' => [
                'php artisan migrate',
                'php artisan migrate:status — look for anything marked Pending.',
            ],
        ],
        [
            'match' => 'Duplicate entry',
            'title' => 'A unique index rejected the write',
            'what' => 'Something tried to insert a row that already exists. This is the database protecting you, not failing — but it reaching the user means the code did not check first, or two requests raced.',
            'fix' => [
                'Use firstOrCreate / updateOrCreate instead of create where a duplicate is possible.',
                'If it is a race, insertOrIgnore or a unique lock is the fix, not a check-then-insert.',
            ],
        ],
        [
            'match' => 'Connection refused',
            'title' => 'The database is not answering',
            'what' => 'MySQL is not running, or the credentials in .env point somewhere that is not listening. Nothing in the application can work until this is fixed — including, mostly, this screen.',
            'fix' => [
                'Check the database is running in Herd.',
                'Confirm DB_HOST, DB_PORT and DB_DATABASE in .env.',
                'php artisan config:clear after changing .env.',
            ],
        ],
        [
            'match' => '__PHP_Incomplete_Class',
            'title' => 'An Eloquent model was put in the cache',
            'what' => 'Models and Eloquent collections are serialised with NUL bytes in the keys of their protected properties, and those bytes do not survive a MySQL text column. The value comes back as an unusable husk. The cruelty of this one is that it fails on the SECOND read — the first request writes and returns fine, so it looks unrelated to whatever you just changed.',
            'fix' => [
                'Never cache a model or an Eloquent collection. Cache plain arrays and rehydrate.',
                'Find the Cache::remember() that returns a query result and add ->toArray().',
            ],
        ],
        [
            'match' => 'Route [',
            'title' => 'A route name does not exist',
            'what' => 'route() was called with a name no route declares. Usually a typo, or a route that was renamed or deleted while a link to it stayed behind in a Blade file.',
            'fix' => [
                'php artisan route:list --name=<part of the name>',
                'php artisan route:clear if the route does exist in routes/web.php.',
            ],
        ],
        [
            'match' => 'View [',
            'title' => 'A view file was not found',
            'what' => 'The name passed to view() does not resolve to a file. Dots are directory separators, so pages.admin.storage means resources/views/pages/admin/storage.blade.php — and in this project the ⚡ prefix on single-file components is part of the filename.',
            'fix' => [
                'Check the path, including the ⚡ prefix for Livewire single-file components.',
                'php artisan view:clear',
            ],
        ],
        [
            'match' => 'Allowed memory size',
            'title' => 'PHP ran out of memory',
            'what' => 'Nearly always a query that loaded a whole table into memory. get() on a large table, or a foreach over a collection where a chunk or a cursor was needed.',
            'fix' => [
                'Replace ->get() with ->chunk(500, …) or ->cursor() in the code named in the trace.',
                'Raising memory_limit hides it for a while and it comes back bigger.',
            ],
        ],
        [
            'match' => 'has been attempted too many times',
            'title' => 'A queued job kept failing and was given up on',
            'what' => 'The job hit its retry limit. The interesting error is not this one — it is whatever made the job fail the first time, which is a separate entry in this list.',
            'fix' => [
                'Look in Queue → Failed jobs for the original exception.',
                'Fix that, then retry the job rather than raising the attempt limit.',
            ],
            'route' => 'admin.queue',
            'routeLabel' => 'Open the queue',
        ],
        [
            'match' => 'The process failed',
            'title' => 'An external command failed — probably ffmpeg',
            'what' => 'Audio processing shells out to ffmpeg. The command exited non-zero: the binary is missing, the input file is not where the database says it is, or the file is not really audio.',
            'fix' => [
                'which ffmpeg — confirm it is installed and on PATH for the queue worker too.',
                'Check the file exists on its disk in Storage.',
            ],
            'route' => 'admin.storage',
            'routeLabel' => 'Check storage',
        ],
        [
            'match' => 'NoSuchBucket',
            'title' => 'The storage bucket does not exist',
            'what' => 'The credentials work but the bucket named in .env is not there — a typo, or a bucket created in a different account or region.',
            'fix' => [
                'Confirm the bucket name in .env matches the provider exactly.',
                'Use the Test button on the Storage screen: it writes, reads back and deletes a file, which is the only proof that works.',
            ],
            'route' => 'admin.storage',
            'routeLabel' => 'Test the disks',
        ],
        [
            'match' => 'SignatureDoesNotMatch',
            'title' => 'The storage keys are wrong',
            'what' => 'The access key or the secret does not match, or the endpoint belongs to a different account. The request reached the provider and was rejected — so the network is fine and the credentials are not.',
            'fix' => [
                'Re-copy the key and secret; a trailing space in .env is the usual culprit.',
                'php artisan config:clear after editing .env.',
            ],
            'route' => 'admin.storage',
            'routeLabel' => 'Test the disks',
        ],
        [
            'match' => 'Undefined array key',
            'title' => 'A key that was assumed to be there was not',
            'what' => 'Reading an array key that does not exist. Usually a response, a config array or a JSON column that has a different shape than the code expects — often because the row is older than the code.',
            'fix' => [
                'Use $array[\'key\'] ?? null at the line in the trace.',
                'If it should always be there, the real bug is wherever the array is built.',
            ],
        ],
        [
            'match' => 'Attempt to read property',
            'title' => 'A property was read on null',
            'what' => 'A relation or a lookup returned null and the code carried on as if it had an object. In this project it is usually a sound with no category, no licence, or a user who has been deleted.',
            'fix' => [
                'Use ?-> at the line in the trace.',
                'Decide whether null is legitimate here. If it is not, the bug is upstream, where the null came from.',
            ],
        ],
        [
            'match' => 'Class "',
            'title' => 'A class could not be autoloaded',
            'what' => 'PSR-4 maps one class to one file whose name matches. Two classes in the same file, a namespace that does not match the folder, or a typo in the name all produce this — and the second class in a file only works while the first one happens to be loaded, so it fails intermittently.',
            'fix' => [
                'One class per file, named after the class, in the folder its namespace names.',
                'composer dump-autoload',
            ],
        ],
        [
            'match' => 'Maximum execution time',
            'title' => 'A request ran too long',
            'what' => 'PHP stopped it at the time limit. Long work does not belong in a web request — the visitor is waiting the whole time and any interruption loses everything.',
            'fix' => [
                'Move the work to a queued job and return immediately.',
                'The upload pipeline already does this — see ProcessSoundUpload for the shape.',
            ],
            'route' => 'admin.queue',
            'routeLabel' => 'Open the queue',
        ],
    ];

    /**
     * Matched on the exception CLASS, when no message pattern fits.
     *
     * @var array<string, array{title: string, what: string, fix: array<int, string>}>
     */
    private const BY_CLASS = [
        'TypeError' => [
            'title' => 'A value of the wrong type was passed',
            'what' => 'A function received something it does not accept. In this project the classic one is a date: Date::use(CarbonImmutable::class) is set, so anything type-hinted Carbon breaks — the type hint must be CarbonInterface.',
            'fix' => [
                'Read the signature in the message: it names what was expected and what arrived.',
                'For dates, type-hint CarbonInterface, never Carbon.',
            ],
        ],
        'Illuminate\Database\QueryException' => [
            'title' => 'The database rejected a query',
            'what' => 'The SQL reached MySQL and came back with an error. The driver message at the start of the text is the real explanation.',
            'fix' => [
                'php artisan migrate:status — confirm nothing is pending.',
                'Read the SQLSTATE message; it is more specific than the exception class.',
            ],
        ],
        'Illuminate\Contracts\Filesystem\FileNotFoundException' => [
            'title' => 'A file is not where the database says it is',
            'what' => 'A sound_files row points at a path its disk does not have. Either the file was removed outside the application, or it was written to a different disk than the row records.',
            'fix' => [
                'Run the missing-files scan on the Storage screen.',
                'Reprocess the sound if the original is still there.',
            ],
            'route' => 'admin.storage',
            'routeLabel' => 'Scan for missing files',
        ],
        'ErrorException' => [
            'title' => 'A PHP notice or warning was raised as an exception',
            'what' => 'Laravel turns warnings into exceptions so they cannot be ignored. The message is a plain PHP warning — an undefined variable, a bad argument, a division by zero.',
            'fix' => [
                'Fix the warning at the line in the trace. It is a real bug, not noise.',
            ],
        ],
    ];

    /**
     * The hint for a group, if there is one.
     *
     * Returning null is a perfectly good answer. A made-up explanation is
     * worse than none: somebody will follow it.
     *
     * @return array{title: string, what: string, fix: array<int, string>, route?: string, routeLabel?: string}|null
     */
    public static function for(ErrorGroup $group): ?array
    {
        $haystack = $group->class.' '.$group->message;

        foreach (self::BY_MESSAGE as $hint) {
            if (Str::contains($haystack, $hint['match'], ignoreCase: true)) {
                return collect($hint)->except('match')->all();
            }
        }

        foreach (self::BY_CLASS as $class => $hint) {
            if ($group->class === $class || class_basename($group->class) === $class) {
                return $hint;
            }
        }

        return null;
    }
}
