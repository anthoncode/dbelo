<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Contracts\RegisterResponse;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->configureLoginRedirect();
        $this->configureRegisterRedirect();
    }

    /**
     * Straight from the form to "check your inbox".
     *
     * Fortify signs a new account in and sends it to config('fortify.home'),
     * which is /library — an empty library, with nothing anywhere saying an
     * email was just sent. The person then meets the verification screen
     * days later, at the moment they try to download something, and has to
     * go looking for a message they never knew existed.
     *
     * This is the whole point of verifying AT SIGN-UP: the inbox is open,
     * the address was typed thirty seconds ago, and a typo in it is still
     * fresh enough to be recognised. Ten minutes later it is a mystery.
     *
     * Only for an address that actually needs confirming. A Google sign-up
     * arrives already verified, so it goes where it always went, and so
     * does everybody else the day the feature is switched off.
     */
    private function configureRegisterRedirect(): void
    {
        $this->app->singleton(RegisterResponse::class, fn () => new class implements RegisterResponse
        {
            public function toResponse($request)
            {
                $user = $request->user();

                $unverified = $user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail();

                if ($request->wantsJson()) {
                    return new JsonResponse('', 201);
                }

                return $unverified
                    ? redirect()->route('verification.notice')
                    : redirect()->intended(config('fortify.home'));
            }
        });
    }

    /**
     * Send people where they actually work.
     *
     * config('fortify.home') is a single constant for the whole application,
     * so on its own it can only be right for one kind of user. Staff want the
     * panel; everybody else must never be sent to a screen that will refuse
     * them. Binding the response contract is the supported way to decide this
     * per user, and it is the only place in the app that knows both the role
     * and the destination.
     *
     * redirect()->intended() still wins when there was a target: someone who
     * clicked a sound while signed out lands on that sound after logging in,
     * not on the panel. Being taken somewhere else is how you lose what you
     * were doing.
     */
    private function configureLoginRedirect(): void
    {
        $destination = function (Request $request): string {
            return $request->user()?->isAdmin()
                ? route('admin.dashboard')
                : route('library');
        };

        $response = fn (Request $request) => $request->wantsJson()
            ? new JsonResponse('', 204)
            : redirect()->intended($destination($request));

        // Two responses, because two-factor sign-in finishes on its own
        // contract — bind only the first and every 2FA login goes back to
        // the generic home path.
        $this->app->singleton(LoginResponse::class, fn () => new class($response) implements LoginResponse
        {
            public function __construct(private $handler) {}

            public function toResponse($request)
            {
                return ($this->handler)($request);
            }
        });

        $this->app->singleton(TwoFactorLoginResponse::class, fn () => new class($response) implements TwoFactorLoginResponse
        {
            public function __construct(private $handler) {}

            public function toResponse($request)
            {
                return ($this->handler)($request);
            }
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);

        /*
         * Our own password-update action, for one reason: Fortify's default
         * changes the password and leaves every other session signed in. See
         * UpdateUserPassword for why that is the hole it looks like.
         */
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn () => view('pages::auth.login'));
        Fortify::verifyEmailView(fn () => view('pages::auth.verify-email'));
        Fortify::twoFactorChallengeView(fn () => view('pages::auth.two-factor-challenge'));
        Fortify::confirmPasswordView(fn () => view('pages::auth.confirm-password'));
        Fortify::registerView(fn () => view('pages::auth.register'));
        Fortify::resetPasswordView(fn () => view('pages::auth.reset-password'));
        Fortify::requestPasswordResetLinkView(fn () => view('pages::auth.forgot-password'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('passkeys', function (Request $request) {
            $credentialId = $request->input('credential.id');

            return Limit::perMinute(10)->by(
                ($credentialId ?: $request->session()->getId()).'|'.$request->ip(),
            );
        });
    }
}
