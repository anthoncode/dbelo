{{--
    ══════════════════════════════════════════════════════════════════════
    THE 500
    ══════════════════════════════════════════════════════════════════════

    Everything here is inline and self-contained: no layout, no @vite, no
    Blade components, no icons from a stylesheet, no queries, no cache, no
    config beyond one plain array lookup.

    That is not caution for its own sake. This page renders when something
    in the application is broken, and the most common somethings are exactly
    the ones a normal page depends on:

      · the database is down        → the site layout reads settings from it
      · the Vite manifest is missing → @vite throws, and the error page dies
        while rendering the error
      · a deploy is half-finished   → some views are new, some are not

    That second one is not hypothetical: this project's log carried
    "Vite manifest not found at public/build/manifest.json" earlier today.
    An error page built on the site layout would have thrown on that line
    and shown the visitor a blank white screen instead of this.

    So: same palette as site-closed.blade.php, drawn by hand, depending on
    nothing. It should look like dbelo even when nothing else does.

    ── WHAT IT DOES NOT SAY ─────────────────────────────────────────────

    It does not say "our team has been notified". Errors are recorded by
    App\Services\ErrorReporter, which writes to the database — the thing
    that may well be the reason this page is showing. A promise that is
    false exactly when it matters is worse than no promise.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Something broke — {{ config('app.name', 'dbelo') }}</title>
    <style>
        :root {
            --ink: #0d0b10;
            --paper: #f4f1f6;
            --brand: #a32eb7;
            --action: #f9510f;
            --danger: #f90f3b;
        }

        * { box-sizing: border-box; }

        html, body { margin: 0; min-height: 100%; }

        body {
            display: grid;
            place-items: center;
            padding: 2rem 1.5rem;
            background: var(--ink);
            color: var(--paper);
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            -webkit-font-smoothing: antialiased;
        }

        body::before {
            content: "";
            position: fixed;
            inset: 0;
            background:
                radial-gradient(60rem 40rem at 20% -10%, rgba(163, 46, 183, 0.16), transparent 70%),
                radial-gradient(45rem 30rem at 100% 110%, rgba(249, 15, 59, 0.10), transparent 70%);
            pointer-events: none;
        }

        .card { position: relative; width: 100%; max-width: 34rem; text-align: center; }

        /*
         * A waveform that has been cut off mid-sound.
         *
         * site-closed uses five bars gently bobbing — something is coming.
         * Here the bars stop dead halfway across and the rest is a flat
         * line: the signal was there and then it was not. Static, because
         * animation on an error reads as "working on it", which is a claim
         * this page cannot make.
         */
        .wave {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.32rem;
            height: 2.6rem;
            margin-bottom: 2.2rem;
        }

        .wave span {
            width: 0.28rem;
            border-radius: 999px;
            background: linear-gradient(180deg, var(--brand), var(--action));
        }

        .wave span.flat {
            height: 1px !important;
            background: rgba(244, 241, 246, 0.22);
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 0.55rem;
            padding: 0.4rem 0.9rem;
            border-radius: 999px;
            font-size: 0.74rem;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            background: rgba(249, 15, 59, 0.14);
            color: var(--danger);
        }

        h1 {
            margin: 1.6rem 0 0;
            font-size: clamp(1.9rem, 6vw, 2.6rem);
            font-weight: 600;
            letter-spacing: -0.035em;
            line-height: 1.1;
        }

        p {
            margin: 1rem auto 0;
            max-width: 44ch;
            font-size: 0.95rem;
            line-height: 1.7;
            color: rgba(244, 241, 246, 0.55);
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 0.75rem;
            margin-top: 2.2rem;
        }

        a.button {
            display: inline-block;
            padding: 0.7rem 1.4rem;
            border-radius: 999px;
            font-size: 0.87rem;
            text-decoration: none;
            transition: filter 0.2s ease, transform 0.2s ease;
        }

        a.primary { background: var(--action); color: #fff; }
        a.ghost { background: rgba(244, 241, 246, 0.08); color: rgba(244, 241, 246, 0.75); }
        a.button:hover { filter: brightness(1.12); transform: translateY(-1px); }

        .name {
            margin-top: 2.8rem;
            font-size: 0.78rem;
            letter-spacing: 0.28em;
            text-transform: uppercase;
            color: rgba(244, 241, 246, 0.28);
        }

        .support { margin-top: 0.9rem; font-size: 0.8rem; color: rgba(244, 241, 246, 0.35); }
        .support a { color: rgba(244, 241, 246, 0.6); }
    </style>
</head>
<body>
    <main class="card">
        {{-- Six bars of signal, then the line goes flat. --}}
        <div class="wave" aria-hidden="true">
            <span style="height: 35%"></span>
            <span style="height: 70%"></span>
            <span style="height: 100%"></span>
            <span style="height: 55%"></span>
            <span style="height: 80%"></span>
            <span style="height: 30%"></span>
            <span class="flat" style="width: 9rem"></span>
        </div>

        <span class="badge">Error 500</span>

        <h1>Something broke on our side.</h1>

        <p>
            Not your connection and not the address — this one is ours. Nothing you were doing has been lost;
            trying again in a moment usually works.
        </p>

        <div class="actions">
            {{-- No JavaScript reload: this page must work with scripts
                 blocked, and a plain link to the same URL does the same job. --}}
            <a class="button primary" href="{{ url()->current() }}">Try again</a>
            <a class="button ghost" href="{{ url('/') }}">Go to the homepage</a>
        </div>

        <p class="support">
            Still broken? Write to
            <a href="mailto:{{ config('dbelo.legal.support_email', 'support@dbelo.com') }}">{{ config('dbelo.legal.support_email', 'support@dbelo.com') }}</a>
            and say what you were doing.
        </p>

        <div class="name">{{ config('app.name', 'dbelo') }}</div>
    </main>
</body>
</html>
