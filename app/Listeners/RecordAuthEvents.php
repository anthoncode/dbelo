<?php

namespace App\Listeners;

use App\Models\LoginAttempt;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Writes the access log from events Laravel already fires.
 *
 * Nothing here hooks the login controller, and that is the point: Fortify,
 * the passkey flow and the two-factor challenge all sign people in by
 * different routes, but every one of them fires these events. Listening to
 * the events means a login path added later is recorded without anybody
 * remembering to add it.
 *
 * Like the audit log and the error reporter, this must never throw. A
 * failure to record an attempt cannot be allowed to prevent the attempt.
 */
class RecordAuthEvents
{
    public function handleLogin(Login $event): void
    {
        $this->write(
            outcome: 'success',
            email: $event->user->getAuthIdentifierName() === 'email' ? $event->user->email : null,
            userId: $event->user->getKey(),
            isAdmin: method_exists($event->user, 'isAdmin') && $event->user->isAdmin(),
        );
    }

    public function handleFailed(Failed $event): void
    {
        $this->write(
            outcome: 'failed',
            // The address AS TYPED, not the user it resolves to. Somebody
            // guessing addresses that do not exist is exactly the pattern
            // worth seeing, and resolving would erase it.
            email: $event->credentials['email'] ?? null,
            userId: $event->user?->getKey(),
            isAdmin: $event->user && method_exists($event->user, 'isAdmin') && $event->user->isAdmin(),
        );
    }

    /**
     * The rate limiter refused the attempt.
     *
     * Recorded separately from a plain failure because it means something
     * different: the protection worked. Without this row the screen cannot
     * show that the brute-force limit is doing anything, and a limit you
     * cannot see working is one you trust on faith.
     */
    public function handleLockout(Lockout $event): void
    {
        $this->write(
            outcome: 'lockout',
            email: $event->request->input('email'),
            userId: null,
            isAdmin: false,
        );
    }

    private function write(string $outcome, ?string $email, ?int $userId, bool $isAdmin): void
    {
        try {
            LoginAttempt::create([
                'email' => $email ? Str::limit($email, 190, '') : null,
                'user_id' => $userId,
                'ip_address' => request()->ip(),
                'user_agent' => Str::limit((string) request()->userAgent(), 490, ''),
                'outcome' => $outcome,
                'is_admin' => $isAdmin,
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('Could not record a login attempt', ['error' => $e->getMessage()]);
        }
    }

    /** @return array<string, string> */
    public function subscribe(): array
    {
        return [
            Login::class => 'handleLogin',
            Failed::class => 'handleFailed',
            Lockout::class => 'handleLockout',
        ];
    }
}
