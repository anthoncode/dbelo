<?php

namespace App\Services\PayPal;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * Something went wrong talking to PayPal.
 *
 * Carries the two things that make a PayPal failure diagnosable and that a
 * plain RuntimeException would throw away:
 *
 *  - the debug id (PayPal-Debug-Id header). This is the only identifier
 *    PayPal support will accept. Without it, a ticket is "a payment failed
 *    yesterday" and goes nowhere.
 *  - the issue name from the body (`name`, or the first `details[].issue`),
 *    which is what actually distinguishes "the card was declined" from "the
 *    plan id does not exist" — two problems with completely different fixes
 *    and, in PayPal's API, the same 422 status.
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

        return new self(
            sprintf(
                'PayPal %s %s failed [%d]%s: %s%s',
                strtoupper($method),
                $path,
                $response->status(),
                $issue ? ' '.$issue : '',
                $detail,
                $debugId ? ' (debug-id '.$debugId.')' : '',
            ),
            $response->status(),
            $debugId,
            $issue,
            $body,
        );
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
        ], fn ($v) => $v !== null && $v !== 0);
    }
}
