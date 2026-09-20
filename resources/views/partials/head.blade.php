@php
    // Every page can override these by calling view()->share('seo', [...])
    // in its mount(). Whatever is missing falls back to a sane default.
    //
    // The description default comes from config, NOT from a string typed
    // here. It used to be typed here, which meant the "Site description"
    // field in Admin → Settings → General saved a value that nothing on the
    // site ever read: the setting existed, the page kept printing the
    // hard-coded sentence, and nothing failed anywhere. A setting whose
    // consumer does not read it is worse than no setting at all — it is a
    // control that lies about being connected.
    $seo = array_merge([
        'title' => null,
        'description' => config('dbelo.site.description'),
        // NOT url()->current(): that drops ?page, and a canonical that
        // says page two is page one is a canonical that deletes page two.
        // See App\Support\Canonical for which parameters survive.
        'canonical' => \App\Support\Canonical::current(),
        // The uploaded share image wins; og-default.png is the fallback
        // and now really is in public/ — it was generated on 13 Sep 2026,
        // 1200x630 with the wordmark, so a link shared before anybody has
        // uploaded anything still paints a card.
        'image' => \App\Support\Appearance::url('social_image') ?? asset('og-default.png'),
        'type' => 'website',
        'jsonld' => null,
        'noindex' => false,

        // What the share image shows, and how big it is. Both matter and for
        // different reasons: WhatsApp and LinkedIn will not paint a preview
        // until they know the dimensions, so without these the card appears
        // a second late or not at all — and alt text is the only thing a
        // screen reader has to go on when a link is read aloud.
        'image_alt' => 'dbelo — sound effects library',
        'image_width' => 1200,
        'image_height' => 630,

        // The preview MP3, when the page is about one sound. Lets a platform
        // that supports it play without leaving the feed.
        'audio' => null,

        // Only read when type is 'article'. Kept in the defaults anyway so
        // every page has the key and nothing here has to test for its
        // existence before its value.
        'published_time' => null,
        'modified_time' => null,
        'author' => null,
        'section' => null,

    // Nulls are stripped before merging. array_merge lets a shared key
    // override even when its value is null, so a page that shares
    // ['description' => null] would blank the meta description rather than
    // fall through to the default — an override that erases instead of
    // replacing. Stripping them makes "I have nothing to say about this
    // one" and "I did not mention it" behave the same way, which is what
    // anybody writing a page would expect.
    ], array_filter($seo ?? [], fn ($value) => $value !== null));

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

{{-- ── THE FEED NOBODY COULD FIND ──────────────────────────────────────
     /blog/feed has worked since the blog was built and nothing on any
     page pointed at it, so the only way to reach it was to already know
     the address. This line is what a reader, a browser or an aggregator
     looks for. Drawn only once there is something published: advertising
     an empty feed is a promise the site does not keep. --}}
@if (\App\Models\Post::blogHasPosts())
    <link rel="alternate" type="application/rss+xml"
          title="{{ config('app.name', 'dbelo') }} — blog"
          href="{{ route('blog.feed') }}">
@endif

@if ($seo['noindex'])
    <meta name="robots" content="noindex, follow">
@else
    {{-- max-image-preview:large is what lets Google put a full-width image
         beside the result instead of a thumbnail or nothing. It is opt-in:
         the default without this line is the small one. The other two lift
         the caps on snippet length and video preview, which cost nothing
         here and are the difference between a two-line snippet and a
         four-line one. --}}
    <meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">
@endif

<meta property="og:site_name" content="{{ config('app.name', 'dbelo') }}">
<meta property="og:type" content="{{ $seo['type'] }}">
<meta property="og:title" content="{{ $fullTitle }}">
<meta property="og:description" content="{{ $seo['description'] }}">
<meta property="og:url" content="{{ $seo['canonical'] }}">
<meta property="og:image" content="{{ $seo['image'] }}">
<meta property="og:image:width" content="{{ $seo['image_width'] }}">
<meta property="og:image:height" content="{{ $seo['image_height'] }}">
<meta property="og:image:alt" content="{{ $seo['image_alt'] }}">
<meta property="og:locale" content="en_US">

@if ($seo['audio'])
    <meta property="og:audio" content="{{ $seo['audio'] }}">
    <meta property="og:audio:type" content="audio/mpeg">
@endif

{{-- ── WHAT og:type="article" IMPLIES AND NOBODY WAS SENDING ──────────
     Declaring the type without the fields that belong to it is half a
     declaration: the platforms that show a date, an author or a section
     beside a shared link look for exactly these, find nothing, and draw
     the same bare card as for any other page. They cost four lines. --}}
@if ($seo['type'] === 'article')
    @if ($seo['published_time'])
        <meta property="article:published_time" content="{{ $seo['published_time'] }}">
    @endif

    @if ($seo['modified_time'])
        <meta property="article:modified_time" content="{{ $seo['modified_time'] }}">
    @endif

    @if ($seo['author'])
        <meta property="article:author" content="{{ $seo['author'] }}">
    @endif

    @if ($seo['section'])
        <meta property="article:section" content="{{ $seo['section'] }}">
    @endif
@endif

<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{{ $fullTitle }}">
<meta name="twitter:description" content="{{ $seo['description'] }}">
<meta name="twitter:image" content="{{ $seo['image'] }}">
<meta name="twitter:image:alt" content="{{ $seo['image_alt'] }}">

{{-- Structured data. For a sound page this is what makes Google show a
     player right in the results instead of a plain blue link. --}}
@if ($seo['jsonld'])
    <script type="application/ld+json">{!! json_encode($seo['jsonld'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
@endif

@if ($favicon = \App\Support\Appearance::url('favicon'))
    <link rel="icon" href="{{ $favicon }}">
@else
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
@endif
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<link rel="sitemap" type="application/xml" href="{{ route('sitemap') }}">

{{-- Font Awesome Pro 7.3, self-hosted. --}}
@if (file_exists(public_path('vendor/fontawesome/css/all.min.css')))
    <link rel="stylesheet" href="{{ asset('vendor/fontawesome/css/all.min.css') }}">
@endif

{{-- @fonts was here.

     It emitted the stylesheet for Instrument Sans, which vite.config.js was
     downloading and self-hosting on every build — three weights, about
     120KB of woff2 — and which NOTHING uses. The @theme block in app.css
     sets --font-sans to Outfit and --font-display to Playfair Display, and
     both of those arrive through the @import at the top of that file.

     So the site was loading two font families and rendering with the other
     one. Removed here and in vite.config.js together; the typography does
     not change, the page just stops fetching what it never drew with. --}}

@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance

{{-- The advertising network's own script, and nothing when ads are off,
     when this route is on the never-list, or when the visitor pays not to
     see them. A site with advertising switched off must not be loading a
     third party's JavaScript "just in case". --}}
{!! \App\Support\Ads::loader() !!}

{{-- The palette override, LAST — after the compiled stylesheet, so the two
     custom properties it may set actually win. Emits nothing at all unless
     a colour was changed in the panel, which keeps app.css the one place
     the real palette lives. --}}
{!! \App\Support\Appearance::styleTag() !!}

{{-- Custom head code, LAST OF ALL.

     Deliberately after @vite and after the palette override. A pasted tag
     with an unclosed script in it can stop everything that follows it from
     being parsed — and if that happened above, the casualty would be the
     site's own stylesheet, which is a white page rather than a broken
     analytics tag. Down here, a mistake costs the tag and nothing else.

     Carries the Search Console and Bing verification tags too, built from
     labelled fields rather than pasted raw. Nothing at all in the admin
     panel. --}}
{!! \App\Support\CustomCode::head() !!}
