<?php

namespace App\Support;

use PDOException;
use Throwable;

/**
 * Is this exception "the database did not answer", or "your SQL is wrong"?
 *
 * ── WHY THE DISTINCTION IS THE WHOLE FEATURE ─────────────────────────────
 *
 * Both arrive as the same class. Illuminate\Database\QueryException extends
 * PDOException and is thrown for a refused connection and for a typo in a
 * column name alike, so catching QueryException and showing a friendly page
 * would hide real bugs behind "we are having trouble" — the failure mode
 * where a site looks briefly unwell for three weeks because nobody saw the
 * stack trace that said `Unknown column 'titel'`.
 *
 * So only the codes below get the friendly page. Everything else keeps
 * behaving exactly as it does today: the full trace with APP_DEBUG on, the
 * generic 500 with it off.
 *
 * ── WHY IT WALKS getPrevious() ───────────────────────────────────────────
 *
 * The exception that reaches the handler is rarely the one PDO threw. A
 * connection refused while the session driver is loading the session comes
 * out wrapped, sometimes twice, and the driver code only exists on the
 * innermost one. Checking the outer class alone finds nothing.
 */
final class DatabaseDown
{
    /**
     * MySQL driver codes that mean the server, not the query.
     *
     * 2002 is the one this project actually meets: it is what DBngin not
     * running looks like from PHP's side.
     */
    private const CODES = [
        2002,   // Connection refused / no such socket
        2003,   // Can't connect to MySQL server on host
        2006,   // MySQL server has gone away
        2013,   // Lost connection during query
        1040,   // Too many connections
        1045,   // Access denied — the credentials, not the statement
        1049,   // Unknown database
        1203,   // max_user_connections reached
    ];

    /** How long to tell crawlers and browsers to wait, in seconds. */
    public const RETRY_AFTER = 120;

    public static function matches(?Throwable $e): bool
    {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if (! $cause instanceof PDOException) {
                continue;
            }

            /*
             * SQLSTATE class 08 is "connection exception" in the ANSI
             * standard, and drivers other than MySQL use it. Checked first
             * so this keeps working the day anything but MySQL is behind it.
             */
            if (str_starts_with((string) $cause->getCode(), '08')) {
                return true;
            }

            $code = self::driverCode($cause);

            if ($code !== null && in_array($code, self::CODES, true)) {
                return true;
            }

            // No SQLSTATE at all: the PDO extension for this driver is not
            // installed. Not a query problem either, and the site is just as
            // unable to answer.
            if (str_contains(strtolower($cause->getMessage()), 'could not find driver')) {
                return true;
            }
        }

        return false;
    }

    /**
     * One line for the operator, and only for the operator.
     *
     * The message, with the file path and the stack left out — the point of
     * the page is that nobody sees those. Never the password: the host, the
     * port and the database name are enough to tell a stopped server from a
     * wrong port, and they are the two things actually worth knowing.
     */
    public static function detail(?Throwable $e): string
    {
        $message = '';

        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof PDOException) {
                $message = $cause->getMessage();
            }
        }

        // The first line only. A driver message can carry the whole SQL
        // statement after it, and a statement can carry data.
        $message = trim(strtok($message ?: (string) $e?->getMessage(), "\n") ?: '');

        $connection = (string) config('database.default');
        $config = config("database.connections.{$connection}", []);

        $where = implode(' · ', array_filter([
            $connection,
            isset($config['host']) ? $config['host'].':'.($config['port'] ?? '?') : null,
            $config['database'] ?? null,
        ]));

        return trim($message.'  —  '.$where, ' —');
    }
}
