<?php

namespace App\Http\Middleware;

use App\Services\Captcha;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The captcha check, in one place instead of four.
 *
 * Hooked to the REQUEST rather than to each form's action, and that is the
 * whole design. Registration goes through Fortify's CreateNewUser, login
 * through Fortify's own pipeline, password reset through a third controller
 * inside the package, and the copyright form through a Livewire component.
 * Four different places to add the same four lines, four different places to
 * forget them — and forgetting is silent, because a form with no captcha
 * check looks exactly like a form that passed one.
 *
 * Here there is one list, and it is readable in ten seconds.
 *
 * Fortify's routes belong to the package, so this appends to the whole web
 * group and matches on path and method. That costs a string comparison on
 * every request, and buys never having to modify a vendor route.
 */
class VerifyCaptcha
{
    /**
     * POST path → the form name Captcha::protects() understands.
     *
     * Login is absent on purpose. It is the one form whose answer depends on
     * what has just happened, so it is asked separately.
     *
     * @var array<string, string>
     */
    private const FORMS = [
        'register' => 'register',
        'forgot-password' => 'password',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('POST')) {
            return $next($request);
        }

        $captcha = app(Captcha::class);
        $path = trim($request->path(), '/');

        $required = match (true) {
            isset(self::FORMS[$path]) => $captcha->protects(self::FORMS[$path]),
            $path === 'login' => $captcha->requiredForLogin($request),
            default => false,
        };

        if (! $required) {
            return $next($request);
        }

        if ($captcha->verify($request->input($captcha->tokenField()), $request->ip())) {
            return $next($request);
        }

        /*
         * A validation error, not a 403.
         *
         * It lands back on the form the visitor was filling in, with what
         * they typed still there and one sentence explaining it. A 403 is a
         * blank wall, and the person who most often meets it is somebody
         * whose browser blocked the widget — not a bot.
         *
         * Keyed to the token field so it cannot collide with a real field's
         * message.
         */
        throw ValidationException::withMessages([
            $captcha->tokenField() => 'The anti-robot check did not pass. Please try again.',
        ]);
    }
}
