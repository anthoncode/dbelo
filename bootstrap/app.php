<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\BlockIps;
use App\Http\Middleware\CheckSiteStatus;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\RecordVisit;
use App\Http\Middleware\RequireVerifiedEmail;
use App\Http\Middleware\VerifyCaptcha;
use App\Http\Middleware\WatchTraffic;
use App\Services\ErrorReporter;
use App\Services\RedirectResolver;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// No `use Throwable;` here. This file has no namespace, so importing a
// root class is a no-op that PHP warns about — the same trap as the ⚡
// single-file components. The type hint below resolves globally already.

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Runs on every web request: enforces suspensions on sessions that
        // were already open, and keeps last_seen_at current.
        $middleware->web(append: [
            // First in the list on purpose: a refused address should cost as
            // little as possible, and every middleware after this one is work
            // that a flood is making the server do.
            BlockIps::class,
            EnsureAccountIsActive::class,
            // After the suspension check, not before: bypassing the closed
            // door requires being an admin, and a suspended admin should
            // have stopped being one a middleware earlier.
            CheckSiteStatus::class,
            // Checks the anti-robot token on the handful of POSTs that carry
            // one. Appended to the whole group rather than added to each
            // form, because Fortify owns three of those four routes — see
            // VerifyCaptcha for the list and the reasoning.
            VerifyCaptcha::class,
            // Terminable: it runs after the response has been sent, so
            // counting a visit never costs the visitor a millisecond.
            RecordVisit::class,
            // Also terminable: counting requests happens after the response,
            // so neither a visitor nor an attacker waits for it.
            WatchTraffic::class,
        ]);

        /*
        | Named, not appended: unlike the three above, this one applies to
        | exactly two routes. A gate that runs everywhere in order to do
        | nothing almost everywhere is a gate whose real scope nobody can
        | read off the route file.
        */
        $middleware->alias([
            'verified.setting' => RequireVerifiedEmail::class,
        ]);

        // Gmail and Yahoo POST to the List-Unsubscribe URL from their own
        // servers, with no session and no CSRF token. The random token in the
        // URL is the credential, and the route does nothing but unsubscribe
        // the address it names — so this exception costs nothing and keeps
        // the one-click button working.
        $middleware->validateCsrfTokens(except: [
            'unsubscribe/*',
        ]);

        /*
        | The notification bar's "I closed this" cookie.
        |
        | Every other cookie this app sets is encrypted, and should stay that
        | way. This one is written by JavaScript the instant somebody clicks
        | the close button, and the browser cannot produce Laravel's
        | encrypted format — so left in the default set it would be
        | discarded, unread, on the very next request. The bar would come
        | straight back and the close button would look broken.
        |
        | Nothing is lost by exempting it: it holds a hash of the message and
        | nothing else. No identifier, no account, nothing worth forging —
        | the worst a tampered value can do is hide a banner from the person
        | who tampered with it.
        */
        $middleware->encryptCookies(except: [
            \App\Support\Homepage::BAR_COOKIE,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        /*
        | Errors, grouped, into a table the panel can read.
        |
        | Reporting here rather than replacing the log: `report` ADDS a
        | reporter, so storage/logs/laravel.log keeps receiving everything.
        | That redundancy is the point — a database table cannot record the
        | error that happens while the database is down, and that is exactly
        | when you need to know.
        |
        | ErrorReporter never throws. A reporting callback that fails is an
        | infinite loop, so everything inside it is wrapped and the fallback
        | is the file.
        |
        | Sentry, if it is ever worth paying for, hooks this same place and
        | sits alongside rather than replacing any of it.
        */
        $exceptions->report(function (Throwable $e) {
            app(ErrorReporter::class)->report($e);
        });

        /*
        | Redirects.
        |
        | Hooked to the 404 rather than run as middleware, which buys two
        | things. It costs nothing on a working request — the redirects table
        | is only consulted once Laravel has already decided nothing matches.
        | And a rule can never shadow a live route, because a live route
        | never gets here. That is what makes the screen safe to hand to
        | someone: the worst a wrong rule can do is misroute a URL that was
        | already broken.
        |
        | Returning null falls through to the normal 404 page, which is what
        | happens for every path we have no rule for — after recording it.
        */
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->expectsJson()) {
                return null;
            }

            return app(RedirectResolver::class)->handle($request, $e);
        });
    })->create();
