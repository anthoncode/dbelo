@php
    // Every page can override these by calling view()->share('seo', [...])
    // in its mount(). Whatever is missing falls back to a sane default.
    $seo = array_merge([
        'title' => null,
        'description' => 'Studio-grade sound effects, cleared for commercial use. Listen free, download with an account.',
        'canonical' => url()->current(),
        'image' => asset('og-default.png'),
        'type' => 'website',
        'jsonld' => null,
        'noindex' => false,
    ], $seo ?? []);

    $pageTitle = $seo['title'] ?? $title ?? null;
    $fullTitle = filled($pageTitle)
        ? $pageTitle.' — '.config('app.name', 'dbelo')
        : config('app.name', 'dbelo').' — sound effects library';
@endphp

<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>{{ $fullTitle }}</title>
<meta name="description" content="{{ $seo['description'] }}">

{{-- One canonical URL per page: filters and pagination create dozens of
     variants of the same catalogue, and without this Google splits the
     ranking between them. --}}
<link rel="canonical" href="{{ $seo['canonical'] }}">

@if ($seo['noindex'])
    <meta name="robots" content="noindex, follow">
@endif

<meta property="og:site_name" content="{{ config('app.name', 'dbelo') }}">
<meta property="og:type" content="{{ $seo['type'] }}">
<meta property="og:title" content="{{ $fullTitle }}">
<meta property="og:description" content="{{ $seo['description'] }}">
<meta property="og:url" content="{{ $seo['canonical'] }}">
<meta property="og:image" content="{{ $seo['image'] }}">

<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{{ $fullTitle }}">
<meta name="twitter:description" content="{{ $seo['description'] }}">
<meta name="twitter:image" content="{{ $seo['image'] }}">

{{-- Structured data. For a sound page this is what makes Google show a
     player right in the results instead of a plain blue link. --}}
@if ($seo['jsonld'])
    <script type="application/ld+json">{!! json_encode($seo['jsonld'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
@endif

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<link rel="sitemap" type="application/xml" href="{{ route('sitemap') }}">

{{-- Font Awesome Pro 7.3, self-hosted. --}}
@if (file_exists(public_path('vendor/fontawesome/css/all.min.css')))
    <link rel="stylesheet" href="{{ asset('vendor/fontawesome/css/all.min.css') }}">
@endif

@fonts

@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance
