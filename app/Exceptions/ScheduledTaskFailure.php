<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A scheduled task that failed, with the task named.
 *
 * Its own class rather than a plain RuntimeException, for two reasons that
 * both come down to the error screen.
 *
 * ErrorReporter fingerprints on the exception CLASS as well as the message,
 * so this separates scheduler failures from every other RuntimeException in
 * the application — they share nothing but a base class, and grouping them
 * together would merge problems that are fixed in different files.
 *
 * And it gives ErrorReporter::shouldReport() something precise to match on
 * when it drops Laravel's own anonymous version of the same event. See the
 * note there: one failure must produce one group, not two.
 */
class ScheduledTaskFailure extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $exitCode = 1,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
