{{--
    ══════════════════════════════════════════════════════════════════════
    THE DATABASE IS NOT ANSWERING
    ══════════════════════════════════════════════════════════════════════

    Same rules as errors/500: everything inline, no layout, no @vite, no
    Blade components, no icons, no queries, no cache. Here it is not a
    precaution, it is the definition — this page exists precisely because
    the database is gone, and the site layout reads its settings from the
    database. A page that needed one row to render would throw while
    rendering the error and leave a white screen.

    The one thing it reads is config(), which is files. AppServiceProvider
    already gives up quietly when the settings table cannot be read, so the
    defaults are what comes back here.

    ── WHY THIS IS SEPARATE FROM THE 500 ────────────────────────────────

    Because it is not the same event and the honest words are different.
    A 500 says "something broke, trying again usually works". A database
    that is down is not intermittent: trying again in three seconds gives
    the same page, and telling somebody otherwise wastes their afternoon.

    It also answers 503 rather than 500, with Retry-After. That is not
    decoration: Google's guidance for a temporary outage is 503, and the
    difference is whether a crawler treats the whole catalogue as broken
    or as busy. A site that answers 500 for an hour can lose pages from
    the index; one that answers 503 is asked again later.

    ── THE STRIP AT THE BOTTOM ──────────────────────────────────────────

    Shown only when APP_DEBUG is on, which on this project means the
    operator's own machine. It carries the driver message, the host, the
    port and the database name — never the password, and never a file path
    or a stack. It is there because the alternative is what this page was
    built to replace: a wall of Laravel source with the answer buried in
    the first line.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Temporarily unavailable — {{ config('app.name', 'dbelo') }}</title>
    <style>
        :root {
            --ink: #0d0b10;
            --paper: #f4f1f6;
            --brand: #8a43fd;
            --action: #f9510f;
            --warning: #ffa314;
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
                radial-gradient(60rem 40rem at 20% -10%, rgba(138, 67, 253, 0.16), transparent 70%),
                radial-gradient(45rem 30rem at 100% 110%, rgba(255, 163, 20, 0.10), transparent 70%);
            pointer-events: none;
        }

        .card { position: relative; width: 100%; max-width: 36rem; text-align: center; }

        /*
         * Signal, gap, signal.
         *
         * The 500 page draws a waveform that stops dead and flatlines —
         * something ended. This one draws two halves that are both alive
         * and cannot reach each other, which is what a refused connection
         * actually is. Static, for the same reason as there: animation
         * reads as "working on it", and this page is not working on it.
         */
        .link {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.32rem;
            height: 2.6rem;
            margin-bottom: 2.2rem;
        }

        .link span {
            width: 0.28rem;
            border-radius: 999px;
            background: linear-gradient(180deg, var(--brand), var(--action));
        }

        .link .gap {
            width: 4.5rem;
            height: 1px;
            background: repeating-linear-gradient(
                90deg,
                rgba(244, 241, 246, 0.28) 0 6px,
                transparent 6px 12px
            );
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
            background: rgba(255, 163, 20, 0.14);
            color: var(--warning);
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
            max-width: 46ch;
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

        /* ── Operator only ── */
        .dev {
            margin-top: 3rem;
            padding: 1rem 1.1rem;
            border-radius: 0.9rem;
            background: rgba(244, 241, 246, 0.05);
            text-align: left;
        }

        .dev-label {
            font-size: 0.66rem;
            letter-spacing: 0.2em;
            text-transform: uppercase;
            color: var(--warning);
        }

        .dev code {
            display: block;
            margin-top: 0.6rem;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 0.78rem;
            line-height: 1.6;
            color: rgba(244, 241, 246, 0.8);
            word-break: break-word;
        }

        .dev .hint {
            margin: 0.8rem 0 0;
            font-size: 0.76rem;
            color: rgba(244, 241, 246, 0.35);
            max-width: none;
        }
    </style>
</head>
<body>
    <main class="card">
        <div class="link" aria-hidden="true">
            <span style="height: 45%"></span>
            <span style="height: 85%"></span>
            <span style="height: 60%"></span>
            <span class="gap"></span>
            <span style="height: 70%"></span>
            <span style="height: 40%"></span>
            <span style="height: 90%"></span>
        </div>

        <span class="badge">Temporarily unavailable</span>

        <h1>The library is not reachable right now.</h1>

        <p>
            The site is up, but it cannot reach the catalogue it reads from — so there is nothing to show you yet.
            This is on our side and nothing you did caused it. It is usually back within a few minutes.
        </p>

        <div class="actions">
            <a class="button primary" href="{{ url()->current() }}">Try again</a>
        </div>

        <p class="support">
            If it stays like this, write to
            <a href="mailto:{{ config('dbelo.legal.support_email', 'support@dbelo.com') }}">{{ config('dbelo.legal.support_email', 'support@dbelo.com') }}</a>.
        </p>

        @if (config('app.debug') && filled($detail ?? null))
            <div class="dev">
                <div class="dev-label">Only you can see this</div>
                <code>{{ $detail }}</code>
                <p class="hint">
                    Code 2002 means nothing is listening on that port — on this machine, that is DBngin stopped.
                    Start it and reload; no cache to clear.
                </p>
            </div>
        @endif

        <div class="name">{{ config('app.name', 'dbelo') }}</div>
    </main>
</body>
</html>
