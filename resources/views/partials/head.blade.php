<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>
    {{ filled($title ?? null) ? $title.' - '.config('app.name', 'dbelo') : config('app.name', 'dbelo') }}
</title>

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">

{{-- Font Awesome Pro 7.3, self-hosted.
     Copy css/ and webfonts/ into public/vendor/fontawesome/ and this
     loads automatically. Guarded so nothing 404s before then. --}}
@if (file_exists(public_path('vendor/fontawesome/css/all.min.css')))
    <link rel="stylesheet" href="{{ asset('vendor/fontawesome/css/all.min.css') }}">
@endif

@fonts

@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance
