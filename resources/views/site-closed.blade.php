{{--
    The closed door.

    Everything here is inline and self-contained: no layout, no @vite, no
    components, no queries. This is the page that has to render when the
    reason the site is closed is that something else does not render — a
    stale build, a missing manifest, a half-finished deploy. A maintenance
    page that depends on the asset pipeline is a maintenance page that shows
    an unstyled wall of text on precisely the day it is needed.

    The mark is drawn in CSS rather than loaded, for the same reason.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $status === 'soon' ? 'Coming soon' : 'Back shortly' }} — {{ config('app.name', 'dbelo') }}</title>
    <style>
        :root {
            --ink: #0d0b10;
            --paper: #f4f1f6;
            --brand: #8a43fd;
            --action: #f9510f;
            --info: #03a3e1;
            --warning: #ffa314;
        }

        * { box-sizing: border-box; }

        html, body {
            margin: 0;
            min-height: 100%;
        }

        body {
            display: grid;
            place-items: center;
            padding: 2rem 1.5rem;
            background: var(--ink);
            color: var(--paper);
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            -webkit-font-smoothing: antialiased;
        }

        /* A quiet wash of brand behind everything, so the page reads as part
           of the site rather than as a server error. */
        body::before {
            content: "";
            position: fixed;
            inset: 0;
            background:
                radial-gradient(60rem 40rem at 20% -10%, rgba(138, 67, 253, 0.18), transparent 70%),
                radial-gradient(45rem 30rem at 100% 110%, rgba(249, 81, 15, 0.12), transparent 70%);
            pointer-events: none;
        }

        .card {
            position: relative;
            width: 100%;
            max-width: 34rem;
            text-align: center;
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
        }

        .badge.soon { background: rgba(3, 163, 225, 0.14); color: var(--info); }
        .badge.maintenance { background: rgba(255, 163, 20, 0.14); color: var(--warning); }

        .dot {
            width: 0.45rem;
            height: 0.45rem;
            border-radius: 999px;
            background: currentColor;
            animation: pulse 2.4s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.35; }
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
            max-width: 42ch;
            font-size: 0.95rem;
            line-height: 1.7;
            color: rgba(244, 241, 246, 0.55);
        }

        /* The waveform: five bars, because this is a sound library and a
           blank page says nothing about what is coming. */
        .wave {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.4rem;
            height: 2.6rem;
            margin-bottom: 2.2rem;
        }

        .wave span {
            width: 0.3rem;
            border-radius: 999px;
            background: linear-gradient(180deg, var(--brand), var(--action));
            animation: bob 1.6s ease-in-out infinite;
        }

        .wave span:nth-child(1) { height: 40%; animation-delay: 0s; }
        .wave span:nth-child(2) { height: 75%; animation-delay: 0.15s; }
        .wave span:nth-child(3) { height: 100%; animation-delay: 0.3s; }
        .wave span:nth-child(4) { height: 65%; animation-delay: 0.45s; }
        .wave span:nth-child(5) { height: 35%; animation-delay: 0.6s; }

        @keyframes bob {
            0%, 100% { transform: scaleY(0.55); }
            50% { transform: scaleY(1); }
        }

        @media (prefers-reduced-motion: reduce) {
            .wave span, .dot { animation: none; }
        }

        .name {
            margin-top: 2.6rem;
            font-size: 0.78rem;
            letter-spacing: 0.28em;
            text-transform: uppercase;
            color: rgba(244, 241, 246, 0.28);
        }
    </style>
</head>
<body>
    <main class="card">
        <div class="wave" aria-hidden="true">
            <span></span><span></span><span></span><span></span><span></span>
        </div>

        <span class="badge {{ $status }}">
            <span class="dot"></span>
            {{ $status === 'soon' ? 'Coming soon' : 'Maintenance' }}
        </span>

        <h1>
            @if ($status === 'soon')
                Something is being built here.
            @else
                Back in a moment.
            @endif
        </h1>

        <p>
            @if ($message)
                {{ $message }}
            @elseif ($status === 'soon')
                A library of sound effects, in progress. It is not open yet — there is nothing to hear behind this
                page just now.
            @else
                The site is briefly closed while something is repaired. Nothing has been lost; come back shortly.
            @endif
        </p>

        <div class="name">{{ config('app.name', 'dbelo') }}</div>
    </main>
</body>
</html>
