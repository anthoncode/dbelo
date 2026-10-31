<?php

namespace App\Services\PayPal;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Something went wrong talking to PayPal.
 *
 * Carries the three things that make a PayPal failure diagnosable and that a
 * plain RuntimeException would throw away:
 *
 *  - the debug id (PayPal-Debug-Id header). This is the only identifier
 *    PayPal support will accept. Without it, a ticket is "a payment failed
 *    yesterday" and goes nowhere.
 *  - the issue name from the body (`name`, or the first `details[].issue`),
 *    which is what actually distinguishes "the card was declined" from "the
 *    plan id does not exist" — two problems with completely different fixes
 *    and, in PayPal's API, the same 422 status.
 *  - the offending FIELDS from `details[]`. See fieldsFrom() for why this one
 *    was worth a whole afternoon.
 *
 * userMessage() exists because the raw API message is written for whoever
 * wrote the integration, not for the person at the checkout screen.
 */
class PayPalException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly ?string $debugId = null,
        public readonly ?string $issue = null,
        public readonly array $body = [],
        /** @var string[] Rendered `details[]` entries, e.g. ['type=DIGITAL_GOODS [INVALID_PARAMETER_VALUE]'] */
        public readonly array $fields = [],
    ) {
        parent::__construct($message, $status);
    }

    public static function fromResponse(Response $response, string $method, string $path): self
    {
        $body = [];

        try {
            $body = (array) $response->json();
        } catch (\Throwable) {
            // A gateway error page is not JSON. Keep going: the status code
            // and the debug id are still worth having.
        }

        $issue = $body['name']
            ?? ($body['details'][0]['issue'] ?? null)
            ?? ($body['error'] ?? null);

        $detail = $body['message']
            ?? ($body['details'][0]['description'] ?? null)
            ?? ($body['error_description'] ?? null)
            ?? 'no message';

        $debugId = $response->header('PayPal-Debug-Id') ?: null;
        $fields = self::fieldsFrom($body);

        return new self(
            sprintf(
                'PayPal %s %s failed [%d]%s: %s%s%s',
                strtoupper($method),
                $path,
                $response->status(),
                $issue ? ' '.$issue : '',
                $detail,
                $fields === [] ? '' : ' → '.implode('; ', $fields),
                $debugId ? ' (debug-id '.$debugId.')' : '',
            ),
            $response->status(),
            $debugId,
            $issue,
            $body,
            $fields,
        );
    }

    /**
     * Which field PayPal is actually complaining about.
     *
     * ── WHY THIS EXISTS ─────────────────────────────────────────────────
     *
     * A 400 from PayPal reads, in full: "Request is not well-formed,
     * syntactically incorrect, or violates schema." That one sentence is
     * returned for a missing field, a bad enum value, a malformed amount and
     * a URL that failed a regex. On its own it narrows nothing.
     *
     * The `details` array underneath it is the real answer: it names the
     * exact JSON pointer, the issue code, and — for a rejected value — echoes
     * the value straight back. This class already kept the whole body, and
     * already read `details[0]` for the issue code, but never put the field
     * name anywhere a human would see it. So `paypal:sync-plans` reported the
     * generic sentence and the only way forward was to guess which of a dozen
     * fields was wrong. It was `type`, and the response had said so.
     *
     * ── WHY VALUES ARE TRUNCATED ────────────────────────────────────────
     *
     * This string lands in a log file and on a terminal. PayPal will happily
     * echo back a 2000-character home_url or a 256-character description, and
     * a diagnostic that scrolls off the screen is not a diagnostic.
     *
     * @return string[]
     */
    public static function fieldsFrom(array $body): array
    {
        $lines = [];

        foreach ($body['details'] ?? [] as $detail) {
            if (! is_array($detail)) {
                continue;
            }

            $field = ltrim(trim((string) ($detail['field'] ?? '')), '/');
            $issue = trim((string) ($detail['issue'] ?? ''));
            $value = $detail['value'] ?? null;

            if ($field === '' && $issue === '') {
                continue;
            }

            $line = $field !== '' ? $field : '(body)';

            // is_scalar and not filled(): a rejected `false` or `0` is a
            // perfectly good clue and filled() would discard both.
            if (is_scalar($value) && (string) $value !== '') {
                $line .= '='.Str::limit((string) $value, 60);
            }

            if ($issue !== '') {
                $line .= ' ['.$issue.']';
            }

            $lines[] = $line;
        }

        return $lines;
    }

    /** Network level: no response at all, so no status and no debug id. */
    public static function unreachable(string $method, string $path, \Throwable $previous): self
    {
        return new self(
            sprintf('PayPal %s %s could not be reached: %s', strtoupper($method), $path, $previous->getMessage()),
            0,
        );
    }

    public static function notConfigured(): self
    {
        return new self('PayPal is not configured: set PAYPAL_CLIENT_ID and PAYPAL_CLIENT_SECRET in .env.', 0);
    }

    /**
     * A sentence safe to show a buyer.
     *
     * Deliberately vague about anything that is dbelo's fault. Telling a
     * customer "plan P-XXXX does not exist" invites them to debug an outage
     * they cannot fix; telling them to retry, when retrying will not help,
     * is worse. Both cases end at support, so both say so.
     *
     * Note that fields[] is NOT used here, on purpose: it is written for the
     * operator and naming our own JSON pointers at a checkout screen would
     * leak the shape of the integration to no one's benefit.
     */
    public function userMessage(): string
    {
        return match (true) {
            $this->status === 0 => 'We could not reach PayPal. Please try again in a moment.',
            $this->status === 429 => 'PayPal is busy right now. Please try again in a minute.',
            $this->status >= 500 => 'PayPal is having trouble right now. Please try again shortly.',
            $this->issue === 'INSTRUMENT_DECLINED' => 'PayPal declined that payment method. Try another one.',
            $this->issue === 'PAYER_ACTION_REQUIRED' => 'PayPal needs you to confirm something before we can continue.',
            default => 'We could not complete that payment. Nothing was charged — please contact support if it keeps happening.',
        };
    }

    /** Safe to write to a log or an error report: no credentials in here. */
    public function context(): array
    {
        return array_filter([
            'status' => $this->status,
            'debug_id' => $this->debugId,
            'issue' => $this->issue,
            // Imploded rather than nested: this goes through Monolog's line
            // formatter, which renders a nested array as unreadable JSON.
            'fields' => $this->fields === [] ? null : implode('; ', $this->fields),
        ], fn ($v) => $v !== null && $v !== 0);
    }
}
