<?php

namespace App\Services\PayPal;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The one place in dbelo that talks to PayPal's REST API.
 *
 * Everything above this class — subscriptions, the day pass, webhooks, the
 * admin sync command — speaks in plans and orders. Only this file knows about
 * OAuth tokens, base URLs and debug ids. That split is the point: when PayPal
 * changes an endpoint, there is exactly one file to open.
 *
 * ── WHAT THIS CLASS GUARANTEES ───────────────────────────────────────────
 *
 *  1. The secret never leaves it. Not into a log line, not into an exception
 *     message, not into an error report. See maskedContext().
 *
 *  2. Sandbox by default. mode() treats anything that is not the exact
 *     string 'live' as sandbox. A typo in .env — PAYPAL_MODE=Live, =prod,
 *     =1 — must fail towards "no real money moved", never towards charging
 *     somebody's card from a laptop.
 *
 *  3. Tokens are cached, and the cache key includes the credentials. PayPal
 *     tokens last around nine hours; fetching one per request would triple
 *     the latency of every checkout. Hashing the client id into the key
 *     means swapping credentials invalidates the cache by itself, instead
 *     of leaving a token for the old app in there until it expires.
 *
 *  4. A retry never creates two of something. GET is retried freely. Any
 *     other verb is retried ONLY when the caller supplied a request id,
 *     which PayPal uses as an idempotency key — same id, same result, one
 *     subscription. Without that id, a timeout is reported rather than
 *     retried, because "the response was lost" and "it never happened" look
 *     identical from here and only one of them is safe to repeat.
 *
 * ── WHAT IT DELIBERATELY DOES NOT DO ─────────────────────────────────────
 *
 * No SDK. PayPal's PHP SDKs have a history of being deprecated faster than
 * the API they wrap; this integration needs about eight endpoints and
 * Laravel's HTTP client already covers the hard parts.
 */
class PayPalClient
{
    public const SANDBOX = 'sandbox';

    public const LIVE = 'live';

    private const HOSTS = [
        self::SANDBOX => 'https://api-m.sandbox.paypal.com',
        self::LIVE => 'https://api-m.paypal.com',
    ];

    /**
     * Renew the token this many seconds before PayPal says it expires.
     *
     * A token that dies between "cache says it is valid" and "PayPal reads
     * it" produces a 401 in the middle of a checkout. Five minutes of slack
     * costs nothing against a nine hour lifetime.
     */
    private const TOKEN_SAFETY_MARGIN = 300;

    /** Extra attempts for requests that are safe to repeat. */
    private const MAX_RETRIES = 2;

    public function __construct(private ?string $mode = null) {}

    /** A client pinned to a mode, regardless of .env. Used by the sync command. */
    public static function for(string $mode): self
    {
        return new self($mode);
    }

    /* ═══════════════════════════ Configuration ═══════════════════════════ */

    /**
     * 'sandbox' unless .env says exactly 'live'.
     *
     * See the note at the top: this asymmetry is intentional and is the
     * single most important line in the file.
     */
    public function mode(): string
    {
        $configured = strtolower(trim((string) ($this->mode ?? config('services.paypal.mode'))));

        return $configured === self::LIVE ? self::LIVE : self::SANDBOX;
    }

    public function isLive(): bool
    {
        return $this->mode() === self::LIVE;
    }

    public function baseUrl(): string
    {
        return self::HOSTS[$this->mode()];
    }

    public function currency(): string
    {
        return strtoupper((string) config('services.paypal.currency', 'USD'));
    }

    public function brandName(): string
    {
        return (string) config('services.paypal.brand_name', config('app.name'));
    }

    public function webhookId(): ?string
    {
        $id = trim((string) config('services.paypal.webhook_id'));

        return $id === '' ? null : $id;
    }

    /** Both halves present. Says nothing about whether they are correct. */
    public function isConfigured(): bool
    {
        return $this->clientId() !== null && $this->clientSecret() !== null;
    }

    private function clientId(): ?string
    {
        $value = trim((string) config('services.paypal.client_id'));

        return $value === '' ? null : $value;
    }

    private function clientSecret(): ?string
    {
        $value = trim((string) config('services.paypal.client_secret'));

        return $value === '' ? null : $value;
    }

    /* ═══════════════════════════ Access token ═══════════════════════════ */

    private function tokenCacheKey(): string
    {
        // The client id is hashed rather than stored. A cache driver is a
        // file on disk or a Redis key somebody can list; there is no reason
        // for either to hold something that identifies the merchant account.
        return 'paypal:token:'.$this->mode().':'.substr(hash('sha256', (string) $this->clientId()), 0, 16);
    }

    /**
     * A bearer token, from cache when possible.
     *
     * @throws PayPalException
     */
    public function token(bool $fresh = false): string
    {
        if (! $this->isConfigured()) {
            throw PayPalException::notConfigured();
        }

        if ($fresh) {
            $this->forgetToken();
        }

        $cached = Cache::get($this->tokenCacheKey());

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = $this->send(
            fn () => Http::asForm()
                ->withBasicAuth($this->clientId(), $this->clientSecret())
                ->withHeaders(['Accept' => 'application/json'])
                ->timeout($this->timeout())
                ->connectTimeout($this->connectTimeout())
                ->post($this->baseUrl().'/v1/oauth2/token', ['grant_type' => 'client_credentials']),
            'POST',
            '/v1/oauth2/token',
        );

        if ($response->failed()) {
            // 401 here means the credentials themselves are wrong — worth
            // saying plainly, because the generic message ("could not
            // complete that payment") sends people looking in the wrong place.
            if ($response->status() === 401) {
                throw new PayPalException(
                    'PayPal rejected the credentials for the '.$this->mode().' environment. Check PAYPAL_CLIENT_ID and PAYPAL_CLIENT_SECRET.',
                    401,
                    $response->header('PayPal-Debug-Id') ?: null,
                    'INVALID_CLIENT',
                );
            }

            throw PayPalException::fromResponse($response, 'POST', '/v1/oauth2/token');
        }

        $token = (string) $response->json('access_token');
        $expiresIn = (int) $response->json('expires_in', 0);

        if ($token === '') {
            throw new PayPalException('PayPal returned a token response with no access_token.', $response->status());
        }

        // Never cache for longer than PayPal is willing to honour, and never
        // for a negative amount of time if the field is missing or odd.
        $ttl = max(60, $expiresIn - self::TOKEN_SAFETY_MARGIN);

        Cache::put($this->tokenCacheKey(), $token, $ttl);

        return $token;
    }

    public function forgetToken(): void
    {
        Cache::forget($this->tokenCacheKey());
    }

    /* ═══════════════════════════ Requests ═══════════════════════════ */

    public function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path, $query);
    }

    /**
     * @param  string|null  $requestId  PayPal's idempotency key. Supply one for
     *                                  anything that creates something — the same id replayed
     *                                  returns the original result instead of a second copy.
     */
    public function post(string $path, array $payload = [], ?string $requestId = null): array
    {
        return $this->request('POST', $path, $payload, $requestId);
    }

    public function patch(string $path, array $payload = []): array
    {
        // PATCH is idempotent by construction here (it sets values rather
        // than incrementing them), so it is safe to retry without a key.
        return $this->request('PATCH', $path, $payload, null, retryable: true);
    }

    public function delete(string $path): array
    {
        return $this->request('DELETE', $path, [], null, retryable: true);
    }

    /**
     * @throws PayPalException
     */
    public function request(
        string $method,
        string $path,
        array $payload = [],
        ?string $requestId = null,
        ?bool $retryable = null,
    ): array {
        $method = strtoupper($method);
        $url = $this->baseUrl().'/'.ltrim($path, '/');

        // See the class docblock: a repeat is only safe when it cannot
        // produce a second subscription or a second charge.
        $retryable ??= $method === 'GET' || $requestId !== null;

        $attempt = 0;
        $refreshed = false;

        while (true) {
            $attempt++;

            $headers = [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ];

            if ($requestId !== null) {
                $headers['PayPal-Request-Id'] = $requestId;
            }

            $response = $this->send(
                fn () => Http::withToken($this->token())
                    ->withHeaders($headers)
                    ->timeout($this->timeout())
                    ->connectTimeout($this->connectTimeout())
                    ->send($method, $url, $method === 'GET'
                        ? ['query' => $payload]
                        : ['json' => $payload]),
                $method,
                $path,
                $retryable && $attempt <= self::MAX_RETRIES,
            );

            if ($response === null) {
                // Connection failed and a retry is allowed. Back off and go round.
                $this->pause($attempt);

                continue;
            }

            if ($response->successful()) {
                return $this->decode($response);
            }

            /*
             * 401 after a successful token fetch means the token died early —
             * PayPal revoked it, or the credentials were rotated under us.
             * Worth exactly one clean retry with a fresh token, and only one:
             * a loop here would hammer the auth endpoint on bad credentials.
             */
            if ($response->status() === 401 && ! $refreshed) {
                $refreshed = true;
                $this->forgetToken();

                continue;
            }

            $shouldRetry = $retryable
                && $attempt <= self::MAX_RETRIES
                && ($response->status() === 429 || $response->status() >= 500);

            if ($shouldRetry) {
                $this->pause($attempt);

                continue;
            }

            $exception = PayPalException::fromResponse($response, $method, $path);

            // The debug id is the only thing PayPal support will look at, so
            // it is logged whether or not the caller catches the exception.
            Log::warning('PayPal request failed', $this->maskedContext([
                'method' => $method,
                'path' => $path,
            ]) + $exception->context());

            throw $exception;
        }
    }

    /**
     * Run one HTTP call, turning a connection failure into either null (when
     * the caller is going to retry) or a PayPalException (when it is not).
     *
     * @throws PayPalException
     */
    private function send(callable $call, string $method, string $path, bool $mayRetry = false): ?Response
    {
        try {
            return $call();
        } catch (ConnectionException $e) {
            if ($mayRetry) {
                return null;
            }

            Log::warning('PayPal unreachable', $this->maskedContext([
                'method' => $method,
                'path' => $path,
                'error' => $e->getMessage(),
            ]));

            throw PayPalException::unreachable($method, $path, $e);
        }
    }

    /**
     * Some PayPal endpoints answer 204 with an empty body (cancel, suspend).
     * An empty array is the honest representation of that; returning null
     * would push a null check into every caller.
     */
    private function decode(Response $response): array
    {
        $body = trim($response->body());

        if ($body === '') {
            return [];
        }

        $decoded = $response->json();

        return is_array($decoded) ? $decoded : ['raw' => $body];
    }

    /** 400ms, 800ms. Long enough to matter, short enough not to time out a checkout. */
    private function pause(int $attempt): void
    {
        usleep(min(400_000 * $attempt, 2_000_000));
    }

    private function timeout(): int
    {
        return max(5, (int) config('services.paypal.timeout', 30));
    }

    private function connectTimeout(): int
    {
        return max(2, (int) config('services.paypal.connect_timeout', 10));
    }

    /**
     * Context for a log line.
     *
     * The mode is in here because "it worked yesterday" is almost always
     * "yesterday it was sandbox". The credentials are not, and there is no
     * parameter that can put them here.
     */
    private function maskedContext(array $extra = []): array
    {
        return array_merge(['paypal_mode' => $this->mode()], $extra);
    }

    /* ═══════════════════════════ Webhook signatures ═══════════════════════════ */

    /**
     * Did this webhook really come from PayPal?
     *
     * A webhook endpoint is a public URL that grants subscriptions. Without
     * this check, the entire billing system is "anybody who can POST JSON
     * gets Pro forever", and the URL leaks the first time it appears in a
     * log, a proxy, or a screenshot.
     *
     * Returns false rather than throwing, so a caller can record the event
     * as rejected instead of returning a 500 — PayPal retries 500s, and
     * retrying a forged request is pointless.
     *
     * @param  array  $headers  The request headers, any casing.
     * @param  array  $event  The decoded webhook body.
     */
    public function verifyWebhookSignature(array $headers, array $event): bool
    {
        $webhookId = $this->webhookId();

        if ($webhookId === null) {
            Log::error('PayPal webhook received but PAYPAL_WEBHOOK_ID is not set: cannot verify, rejecting.');

            return false;
        }

        $header = function (string $name) use ($headers): ?string {
            foreach ($headers as $key => $value) {
                if (strcasecmp((string) $key, $name) === 0) {
                    return is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
                }
            }

            return null;
        };

        $payload = [
            'auth_algo' => $header('paypal-auth-algo'),
            'cert_url' => $header('paypal-cert-url'),
            'transmission_id' => $header('paypal-transmission-id'),
            'transmission_sig' => $header('paypal-transmission-sig'),
            'transmission_time' => $header('paypal-transmission-time'),
            'webhook_id' => $webhookId,
            'webhook_event' => $event,
        ];

        foreach (['auth_algo', 'cert_url', 'transmission_id', 'transmission_sig', 'transmission_time'] as $required) {
            if (empty($payload[$required])) {
                Log::warning('PayPal webhook missing signature header', ['missing' => $required]);

                return false;
            }
        }

        try {
            $result = $this->post('/v1/notifications/verify-webhook-signature', $payload);
        } catch (Throwable $e) {
            // PayPal being unreachable is not proof the event is forged. The
            // caller should leave the event unhandled and let PayPal retry,
            // which it does for three days.
            Log::warning('PayPal signature verification could not be completed', ['error' => $e->getMessage()]);

            return false;
        }

        return ($result['verification_status'] ?? null) === 'SUCCESS';
    }

    /* ═══════════════════════════ Helpers ═══════════════════════════ */

    /**
     * An idempotency key for a create.
     *
     * Prefixed with what it is creating so that a PayPal dashboard full of
     * request ids is readable six months from now.
     */
    public static function requestId(string $prefix): string
    {
        return substr($prefix.'-'.Str::uuid()->toString(), 0, 108); // PayPal caps this at 108 chars.
    }

    /** Cents to the decimal string PayPal wants. 1400 → "14.00" */
    public static function amount(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    /**
     * A quick "are the credentials real?" for the Doctor screen.
     *
     * Returns a sentence, not a boolean, because the three failure modes —
     * not configured, wrong credentials, PayPal unreachable — need three
     * different actions.
     */
    public function health(): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'mode' => $this->mode(), 'message' => 'PAYPAL_CLIENT_ID / PAYPAL_CLIENT_SECRET are empty in .env.'];
        }

        try {
            $this->token(fresh: true);
        } catch (PayPalException $e) {
            return ['ok' => false, 'mode' => $this->mode(), 'message' => $e->getMessage()];
        }

        return [
            'ok' => true,
            'mode' => $this->mode(),
            'message' => 'Authenticated against '.$this->baseUrl().'.',
        ];
    }
}
