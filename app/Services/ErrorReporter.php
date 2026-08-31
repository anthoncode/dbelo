<?php

namespace App\Services;

use App\Models\ErrorDaily;
use App\Models\ErrorGroup;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Turns thrown exceptions into rows somebody can act on.
 *
 * Three rules govern everything in here, in order:
 *
 *   1. NEVER THROW. A handler that fails is an infinite loop: the failure
 *      raises an exception, which is reported, which fails. Everything is
 *      wrapped, and the fallback is the log file — which is also why the
 *      file log stays. A table cannot record the error that happens while
 *      the database is down, and that is exactly when you need it most.
 *
 *   2. GROUP ON WRITE. One row per distinct problem. Four thousand copies of
 *      the same stack trace is a table nobody reads and a screen that gets
 *      slower every day.
 *
 *   3. RECORD ONLY REAL ERRORS. A 404, a failed validation, an expired
 *      session and a rate-limit rejection are the application working. Let
 *      those in and the twenty rows that matter drown.
 */
class ErrorReporter
{
    /**
     * Not errors. These are the application behaving correctly, and every
     * one of them is already handled somewhere better: 404s feed the
     * redirects screen, validation goes back to the form, a 429 means the
     * throttle did its job.
     */
    private const IGNORED = [
        ValidationException::class,
        AuthenticationException::class,
        AuthorizationException::class,
        TokenMismatchException::class,
        ModelNotFoundException::class,
        ThrottleRequestsException::class,
    ];

    /** Longest stack trace kept. Past this it is scroll, not information. */
    private const TRACE_LIMIT = 20000;

    /**
     * Storm guard.
     *
     * A loop that throws must not become a hundred thousand UPDATEs on the
     * one table you will need to read afterwards. Occurrences accumulate in
     * the cache and are flushed at most once per fingerprint per this many
     * seconds, carrying the accumulated delta with them.
     */
    private const FLUSH_SECONDS = 10;

    public function report(Throwable $e): void
    {
        try {
            if (! $this->shouldReport($e)) {
                return;
            }

            $fingerprint = $this->fingerprint($e);

            // Count every occurrence, write rarely. The counter is cheap;
            // the row update is not.
            $pending = $this->buffer($fingerprint);

            if (! Cache::add("error.flush.{$fingerprint}", true, self::FLUSH_SECONDS)) {
                return;
            }

            Cache::forget("error.pending.{$fingerprint}");

            $this->persist($e, $fingerprint, max(1, $pending));
        } catch (Throwable $inner) {
            /*
             * The one place in the application that must never rethrow.
             * Straight to the file log, with no framework in the way — if
             * the database is what broke, anything cleverer breaks too.
             */
            Log::error('ErrorReporter failed', [
                'reporting' => $e::class,
                'because' => $inner->getMessage(),
            ]);
        }
    }

    private function shouldReport(Throwable $e): bool
    {
        foreach (self::IGNORED as $ignored) {
            if ($e instanceof $ignored) {
                return false;
            }
        }

        /*
         * Any 4xx is the client's problem, not a fault. Written as a rule
         * rather than a longer class list so an HTTP exception nobody
         * anticipated still lands on the right side of the line.
         */
        if ($e instanceof HttpExceptionInterface && $e->getStatusCode() < 500) {
            return false;
        }

        return true;
    }

    /** @return int occurrences accumulated since the last flush */
    private function buffer(string $fingerprint): int
    {
        $key = "error.pending.{$fingerprint}";

        // add() first so the key exists with a TTL; increment on a missing
        // key returns false on some stores and would silently lose the count.
        if (Cache::add($key, 1, 300)) {
            return 1;
        }

        return (int) (Cache::increment($key) ?: 1);
    }

    /**
     * What makes two occurrences the same problem.
     *
     * class + normalised message + the first frame that belongs to us.
     *
     * The frame matters: the same exception class thrown from two different
     * places is two different bugs, and merging them sends you to the wrong
     * file. Vendor frames are skipped for the mirror image of that reason —
     * every database error would otherwise fingerprint to the same line
     * inside the PDO wrapper.
     */
    public function fingerprint(Throwable $e): string
    {
        $frame = $this->firstAppFrame($e);

        return sha1(implode('|', [
            $e::class,
            $this->normalise($e->getMessage()),
            $frame['file'] ?? '',
            $frame['line'] ?? '',
        ]));
    }

    /**
     * Strip the parts of a message that change every time.
     *
     * This is the detail the whole feature stands on. Without it,
     * "User 431 not found" and "User 892 not found" are two groups, and a
     * thousand of them are a thousand groups — which is a log file again,
     * only slower.
     */
    private function normalise(string $message): string
    {
        $message = str_replace(base_path(), '', $message);

        return trim(preg_replace(
            [
                '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', // uuid
                '/\b[0-9a-f]{16,}\b/i',                                            // hashes, tokens
                '/[\w.+-]+@[\w-]+\.[\w.]+/',                                       // emails
                '/\b\d+\b/',                                                       // any number
                '/\s+/',
            ],
            ['{uuid}', '{hash}', '{email}', '{n}', ' '],
            $message,
        ));
    }

    /**
     * The first stack frame inside app/ — where OUR code went wrong.
     *
     * @return array{file: ?string, line: ?int}
     */
    private function firstAppFrame(Throwable $e): array
    {
        $appPath = base_path('app');

        foreach ($e->getTrace() as $frame) {
            if (isset($frame['file']) && str_starts_with($frame['file'], $appPath)) {
                return ['file' => $this->relative($frame['file']), 'line' => $frame['line'] ?? null];
            }
        }

        // Thrown from a view, a route file or vendor code with nothing of
        // ours below it. Its own location is still better than nothing.
        return ['file' => $this->relative($e->getFile()), 'line' => $e->getLine()];
    }

    private function relative(string $path): string
    {
        return ltrim(str_replace(base_path(), '', $path), '/\\');
    }

    private function persist(Throwable $e, string $fingerprint, int $delta): void
    {
        $frame = $this->firstAppFrame($e);
        $now = now();

        $group = ErrorGroup::firstOrNew(['fingerprint' => $fingerprint]);

        /*
         * A regression: it came back after somebody said it was fixed.
         *
         * This is the most valuable thing the screen can tell you, and it is
         * only knowable here — at the moment a new occurrence lands on a
         * group that was resolved before it.
         */
        if ($group->exists && $group->status === 'resolved') {
            $group->status = 'open';
            $group->regressed_at = $now;
        }

        $group->fill([
            'level' => $e instanceof \Error ? 'critical' : 'error',
            'class' => $e::class,
            'message' => Str::limit($e->getMessage(), 2000),
            'file' => $frame['file'],
            'line' => $frame['line'],
            'last_seen_at' => $now,
            'last_context' => $this->context(),
            'last_trace' => Str::limit($this->trace($e), self::TRACE_LIMIT),
        ]);

        $group->first_seen_at ??= $now;
        $group->count = ($group->count ?? 0) + $delta;
        $group->status ??= 'open';

        $group->save();

        ErrorDaily::add($group->id, $delta);
    }

    /**
     * Enough to reproduce it, and nothing that could be a credential.
     *
     * Request input is NOT captured. It is the single most useful thing an
     * error tracker can hold and also the single most likely place for a
     * password, a card number or a token to end up — in a table that is read
     * by more people and kept far longer than almost any other. The URL, the
     * route and the user are enough to reproduce nearly everything, and they
     * cannot leak a secret.
     *
     * @return array<string, mixed>
     */
    private function context(): array
    {
        if (app()->runningInConsole()) {
            return [
                'source' => 'console',
                'command' => implode(' ', array_slice($_SERVER['argv'] ?? [], 1)) ?: null,
            ];
        }

        $request = request();

        return array_filter([
            'source' => 'web',
            'method' => $request->method(),
            'url' => Str::limit($request->fullUrl(), 500),
            'route' => $request->route()?->getName(),
            'user_id' => auth()->id(),
            'ip' => $request->ip(),
            'agent' => Str::limit((string) $request->userAgent(), 300),
        ], fn ($value) => $value !== null);
    }

    /**
     * The trace, with the project path stripped and the chain of previous
     * exceptions kept.
     *
     * The previous exception is usually the real one: "SQLSTATE…" wrapped in
     * a QueryException wrapped in whatever the framework rethrew. Dropping
     * the chain throws away the sentence that names the actual fault.
     */
    private function trace(Throwable $e): string
    {
        $parts = [];
        $current = $e;
        $depth = 0;

        while ($current !== null && $depth < 4) {
            $parts[] = ($depth === 0 ? '' : "\nCaused by: ")
                .$current::class.': '.$current->getMessage()
                ."\nat ".$this->relative($current->getFile()).':'.$current->getLine()
                ."\n".$current->getTraceAsString();

            $current = $current->getPrevious();
            $depth++;
        }

        return str_replace(base_path(), '', implode("\n", $parts));
    }
}
