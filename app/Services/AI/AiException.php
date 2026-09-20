<?php

namespace App\Services\AI;

use RuntimeException;

/**
 * A suggestion could not be produced.
 *
 * Every one of these is survivable: the sound keeps whatever the filename
 * parser worked out and waits in review like it always did. Nothing about
 * the catalogue depends on a model answering.
 */
class AiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $provider = '',
        public readonly int $status = 0,
        /** Whether trying again later could work. */
        public readonly bool $retryable = false,
    ) {
        parent::__construct($message, $status);
    }

    public static function notConfigured(string $provider): self
    {
        return new self("The {$provider} driver has no API key.", $provider);
    }

    /**
     * Rate limited or temporarily unavailable.
     *
     * Separated from the rest because the free tiers of both providers make
     * this the NORMAL outcome of a large backfill, not a failure. The job
     * puts the sound back in the queue instead of marking it broken.
     */
    public static function throttled(string $provider, int $status): self
    {
        return new self("{$provider} is rate limiting or unavailable ({$status}).", $provider, $status, true);
    }

    public static function refused(string $provider, int $status, string $detail): self
    {
        return new self("{$provider} refused the request ({$status}): {$detail}", $provider, $status, $status >= 500);
    }

    public static function unreadable(string $provider, string $detail): self
    {
        return new self("{$provider} returned something that is not usable: {$detail}", $provider);
    }

    /**
     * The sentence an admin can act on.
     *
     * getMessage() carries the provider's own wording, which is written for
     * whoever wrote the SDK. This says what to DO, and the raw message is
     * printed underneath it rather than instead of it — the same split the
     * PayPal client uses, and for the same reason: "401" is not an
     * instruction, "the key was rejected, paste it again" is.
     */
    public function userMessage(): string
    {
        return match (true) {
            $this->status === 401 || $this->status === 403
                => 'The API key was rejected. Check it was copied whole, and that it has not been revoked at the provider.',

            $this->status === 404
                => 'That model name does not exist at this provider. Model names change; check the current one in their documentation.',

            $this->status === 429
                => 'Rate limited. This is the normal behaviour of a free tier during a burst — it will work again shortly.',

            $this->retryable
                => 'The provider could not be reached or is temporarily unavailable. Nothing is wrong with the settings.',

            $this->status === 0 && $this->provider !== '' && str_contains($this->getMessage(), 'no API key')
                => 'No API key for this provider — neither stored here nor in .env.',

            default => 'The provider answered, but not with something usable.',
        };
    }
}
