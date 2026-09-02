<?php

namespace App\Services;

use App\Models\LoginAttempt;
use App\Support\Security;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * One captcha, three providers, four forms.
 *
 * The abstraction is thin on purpose: every one of these works the same way
 * — a widget puts a token in the form, the server posts that token to the
 * provider and gets a yes or a no. Only the field name, the script URL and
 * the verify endpoint differ. Writing that difference down once costs a few
 * lines now; discovering it later, spread across four form handlers, costs
 * an afternoon per form.
 *
 * IT FAILS OPEN. If the provider is unreachable the form is accepted, and a
 * warning is logged. That is a deliberate trade and it is the less bad one:
 * failing closed means a Google outage takes registration, password reset
 * and the copyright form down together, and the site looks broken to
 * everybody for a reason nobody can see. A captcha that is briefly absent
 * costs some spam; a captcha that is briefly a wall costs real users.
 */
class Captcha
{
    private const VERIFY_URLS = [
        'recaptcha_v2' => 'https://www.google.com/recaptcha/api/siteverify',
        'recaptcha_v3' => 'https://www.google.com/recaptcha/api/siteverify',
        'turnstile' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
    ];

    /** The input each provider's widget writes its token into. */
    public const TOKEN_FIELDS = [
        'recaptcha_v2' => 'g-recaptcha-response',
        'recaptcha_v3' => 'g-recaptcha-response',
        'turnstile' => 'cf-turnstile-response',
    ];

    /** How far back a failed login still counts toward the login threshold. */
    private const LOGIN_WINDOW_MINUTES = 15;

    public function provider(): string
    {
        return Security::choice('captcha_provider');
    }

    /**
     * Configured AND usable.
     *
     * Both keys are required. A provider chosen with no keys would render a
     * broken widget and then refuse every submission — the worst of both:
     * visible failure for real people, no protection at all.
     */
    public function enabled(): bool
    {
        return $this->provider() !== 'off'
            && filled(Security::text('captcha_site_key'))
            && Security::hasSecret('captcha_secret');
    }

    public function siteKey(): string
    {
        return Security::text('captcha_site_key');
    }

    public function tokenField(): string
    {
        return self::TOKEN_FIELDS[$this->provider()] ?? 'g-recaptcha-response';
    }

    /**
     * Is this form protected?
     *
     * `login` is asked separately — see requiredForLogin() — because it is
     * the only one whose answer depends on what has just been happening.
     *
     * @param  string  $form  register · password · contact
     */
    public function protects(string $form): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        return match ($form) {
            'register' => Security::flag('captcha_register'),
            'password' => Security::flag('captcha_password'),
            'contact' => Security::flag('captcha_contact'),
            default => false,
        };
    }

    /**
     * Has this address, or this account, earned a captcha?
     *
     * Counts recent failures from the IP OR against the email, so it catches
     * both shapes of attack: one machine trying many accounts, and many
     * machines trying one. Both indexes for this exist on login_attempts.
     *
     * Never throws. This runs on the login form itself, and a broken counter
     * must not be able to stop people signing in.
     */
    public function requiredForLogin(Request $request): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        $threshold = Security::number('captcha_login_after');

        if ($threshold < 1) {
            return false;
        }

        try {
            if (! Schema::hasTable('login_attempts')) {
                return false;
            }

            $email = (string) $request->input('email', session('captcha.last_email', ''));
            $since = now()->subMinutes(self::LOGIN_WINDOW_MINUTES);

            return LoginAttempt::failed()
                ->where('created_at', '>=', $since)
                ->where(function ($q) use ($request, $email) {
                    $q->where('ip_address', $request->ip());

                    if ($email !== '') {
                        $q->orWhere('email', $email);
                    }
                })
                ->count() >= $threshold;
        } catch (Throwable $e) {
            Log::warning('Captcha login check failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /* ═══════════════════════════ Verifying ═══════════════════════════ */

    /**
     * Ask the provider whether this token is good.
     */
    public function verify(?string $token, ?string $ip = null): bool
    {
        if (! $this->enabled()) {
            return true;
        }

        if (blank($token)) {
            return false;
        }

        $provider = $this->provider();

        try {
            $response = Http::asForm()
                ->timeout(5)
                ->post(self::VERIFY_URLS[$provider], [
                    'secret' => Security::text('captcha_secret'),
                    'response' => $token,
                    'remoteip' => $ip,
                ]);

            if (! $response->successful()) {
                return $this->failOpen('provider returned HTTP '.$response->status());
            }

            $body = $response->json();

            if (! ($body['success'] ?? false)) {
                return false;
            }

            /*
             * v3 answers "how human did that look", not yes or no. A token
             * can be perfectly valid and still come from a script, so the
             * score is the actual decision and skipping it would make v3
             * theatre.
             */
            if ($provider === 'recaptcha_v3') {
                $score = (float) ($body['score'] ?? 0);
                $minimum = (float) Security::choice('captcha_score');

                return $score >= $minimum;
            }

            return true;
        } catch (Throwable $e) {
            return $this->failOpen($e->getMessage());
        }
    }

    /**
     * Let it through, and say so.
     *
     * Reported, not swallowed. It lands in the error groups screen, so a
     * provider that has been unreachable for three days is a row somebody
     * can see rather than a protection everyone believes is on.
     */
    private function failOpen(string $reason): bool
    {
        Log::warning('Captcha verification unavailable — allowing the request', [
            'provider' => $this->provider(),
            'reason' => $reason,
        ]);

        return true;
    }

    /* ═══════════════════════════ Rendering ═══════════════════════════ */

    public function scriptUrl(): ?string
    {
        return match ($this->provider()) {
            'recaptcha_v2' => 'https://www.google.com/recaptcha/api.js',
            'recaptcha_v3' => 'https://www.google.com/recaptcha/api.js?render='.urlencode($this->siteKey()),
            'turnstile' => 'https://challenges.cloudflare.com/turnstile/v0/api.js',
            default => null,
        };
    }
}
