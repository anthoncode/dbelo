<?php

use App\Http\Controllers\DownloadController;
use App\Http\Controllers\ImpersonationController;
use App\Http\Controllers\LegalController;
use App\Http\Controllers\SitemapController;
use Illuminate\Support\Facades\Route;

Route::livewire('/', 'pages::home')->name('home');

Route::get('sitemap.xml', SitemapController::class)->name('sitemap');

/*
| ads.txt.
|
| A route rather than a file in public/, because the content is a setting —
| and because a 404 here is not an error anybody sees: buyers simply treat
| the inventory as unauthorised and bid less. Served as plain text, and
| genuinely 404s when empty, so "not configured" and "configured with
| nothing" cannot be told apart by a crawler either.
*/
Route::get('ads.txt', function () {
    $contents = trim((string) \App\Support\Ads::text('ads_txt'));

    abort_if($contents === '', 404);

    return response($contents, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
})->name('ads.txt');

/*
| Public catalogue. Everyone can browse and listen; only the download
| route is gated, and the gate itself lives in DownloadController.
*/
/*
| The free audio converter.
|
| A PLAIN GET THAT RENDERS A PAGE, and there is deliberately nothing else:
| no upload route, no temporary disk, no queue, no cleanup, no throttle. The
| conversion runs in the visitor's browser through WebAssembly, so this
| server never receives their file.
|
| That is what makes the module safe to run anywhere — including on shared
| hosting, where a public transcoding service would be both technically
| fragile and, in most terms of service, forbidden.
|
| No entry is needed in App\Support\ReservedSlugs: it builds its list from
| the routes that actually exist, so registering this one already stops a
| CMS page from being given the slug "converter".
*/
Route::get('converter', [\App\Http\Controllers\ConverterController::class, 'index'])
    ->name('converter');

/*
| One page per conversion people actually search for.
|
| A WHITELIST, NOT A PATTERN. App\Support\ConverterPairs holds twenty pairs
| and anything else 404s — answering /convert/anything-to-whatever with a
| generated page is how a site accumulates thousands of near-identical URLs
| that Google reads as spam rather than as coverage.
|
| The segment has a slash in it, so the {page} catch-all at the foot of this
| file can never swallow it.
*/
Route::get('convert/{pair}', [\App\Http\Controllers\ConverterController::class, 'pair'])
    ->where('pair', '[a-z0-9]+-to-[a-z0-9]+')
    ->name('converter.pair');

Route::livewire('sounds', 'pages::sounds')->name('sounds.index');

/*
| Downloading.
|
| NO 'auth' MIDDLEWARE, on purpose. Whether a visitor without an account may
| take a file is a setting — Admin → Settings → Downloads — and a setting
| cannot be enforced by a middleware that decided the answer before the
| request reached the code that reads it. The gate is in DownloadController,
| where the plan, the free allowance and the licence are already checked.
|
| 'verified.setting' stays: it lets guests through by design and only bites
| a signed-in account that has not confirmed its address.
|
| 'throttle:downloads' stays, and it is the one that matters more now. The
| free allowance is a cookie and is meant to be resettable; this limiter and
| WatchTraffic are what stop a script walking the catalogue.
*/
Route::get('sounds/{sound}/download', DownloadController::class)
    ->middleware(['verified.setting', 'throttle:downloads'])
    ->name('sounds.download');

/*
| Where a download that did not happen lands.
|
| A real page rather than abort(429): somebody who just tried to take a
| sound is the most interested visitor the site will see all day, and the
| old answer was a grey error page with one sentence on it.
*/
Route::get('downloads/limit', \App\Http\Controllers\DownloadLimitController::class)
    ->name('downloads.limit');

Route::livewire('sounds/{sound}/edit', 'pages::sounds.edit')
    ->middleware('auth')
    ->name('sounds.edit');

Route::livewire('sounds/{sound}', 'pages::sounds.show')->name('sounds.show');

/*
| Contributors. The component itself checks canUpload() in mount(),
| so the role check lives next to the thing it protects.
*/
Route::livewire('library', 'pages::library')
    ->middleware('auth')
    ->name('library');

Route::livewire('collections/{collection}', 'pages::collections.show')
    ->name('collections.show');

/*
| Packs.
|
| The same table as collections — a pack IS a collection with is_featured —
| but a different URL on purpose. /collections/{slug} serves somebody's
| private folder; /packs/{slug} is curated product, and it is the URL that
| has to rank for "podcast sound effects". The component scopes the lookup
| to featured + public, so a private collection 404s here rather than
| leaking through the other name.
|
| Static segment first so "packs" can never be swallowed by {pack}.
*/
Route::livewire('packs', 'pages::packs')->name('packs.index');
Route::livewire('packs/{pack}', 'pages::packs.show')->name('packs.show');

Route::livewire('upload', 'pages::upload')
    ->middleware(['auth', 'verified.setting'])
    ->name('upload');

/*
| Admin panel. The role check lives inside each component's mount(), next
| to the thing it protects, rather than in a middleware far from it.
*/
Route::livewire('admin', 'pages::admin.dashboard')
    ->middleware('auth')
    ->name('admin.dashboard');

Route::livewire('admin/categories', 'pages::admin.categories')
    ->middleware('auth')
    ->name('admin.categories');

Route::livewire('admin/tags', 'pages::admin.tags')
    ->middleware('auth')
    ->name('admin.tags');

Route::livewire('admin/users', 'pages::admin.users')
    ->middleware('auth')
    ->name('admin.users');

Route::livewire('admin/users/{user}', 'pages::admin.users.show')
    ->middleware('auth')
    ->name('admin.users.show');

Route::get('admin/impersonate/{user}', [ImpersonationController::class, 'start'])
    ->middleware('auth')
    ->name('admin.impersonate');

Route::get('impersonate/stop', [ImpersonationController::class, 'stop'])
    ->middleware('auth')
    ->name('impersonate.stop');

/*
| Content. Pages and posts share one table, one editor and one list screen;
| the route name is what tells the components which of the two they are.
*/
Route::livewire('admin/pages', 'pages::admin.posts')
    ->middleware('auth')
    ->name('admin.pages');

Route::livewire('admin/pages/create', 'pages::admin.posts.edit')
    ->middleware('auth')
    ->name('admin.pages.create');

// Bound by id, not slug: a page and a post may legitimately share a slug.
Route::livewire('admin/pages/{post:id}/edit', 'pages::admin.posts.edit')
    ->middleware('auth')
    ->name('admin.pages.edit');

Route::livewire('admin/blog', 'pages::admin.posts')
    ->middleware('auth')
    ->name('admin.blog');

Route::livewire('admin/blog/create', 'pages::admin.posts.edit')
    ->middleware('auth')
    ->name('admin.blog.create');

Route::livewire('admin/blog/{post:id}/edit', 'pages::admin.posts.edit')
    ->middleware('auth')
    ->name('admin.blog.edit');

Route::livewire('admin/analytics', 'pages::admin.analytics')
    ->middleware('auth')
    ->name('admin.analytics');

Route::livewire('admin/sounds', 'pages::admin.sounds')
    ->middleware('auth')
    ->name('admin.sounds');

/*
| The URI nests under sounds, the ROUTE NAME deliberately does not.
|
| AdminNav::matches() lights a menu entry with a trailing wildcard, so a
| name of "admin.sounds.bulk" would light both "Sounds" and "Bulk upload"
| at once. The wildcard is there so a detail page keeps its list
| highlighted, which is right — these are simply two separate screens.
*/
Route::livewire('admin/sounds/bulk', 'pages::admin.sounds.bulk')
    ->middleware('auth')
    ->name('admin.bulk-upload');

Route::livewire('admin/queue', 'pages::admin.queue')
    ->middleware('auth')
    ->name('admin.queue');

Route::livewire('admin/storage', 'pages::admin.storage')
    ->middleware('auth')
    ->name('admin.storage');

Route::livewire('admin/activity', 'pages::admin.activity')
    ->middleware('auth')
    ->name('admin.activity');

Route::livewire('admin/errors', 'pages::admin.errors')
    ->middleware('auth')
    ->name('admin.errors');

Route::livewire('admin/diagnostics', 'pages::admin.diagnostics')
    ->middleware('auth')
    ->name('admin.diagnostics');

Route::livewire('admin/backups', 'pages::admin.backups')
    ->middleware('auth')
    ->name('admin.backups');

Route::livewire('admin/seo', 'pages::admin.seo')
    ->middleware('auth')
    ->name('admin.seo');

/*
| Security. A group rather than one screen, because watching and configuring
| are different activities done at different moments: these two are for
| watching. The settings — required 2FA, captcha, email verification — belong
| under Settings, where things get changed.
*/
Route::livewire('admin/security/access', 'pages::admin.security.access')
    ->middleware('auth')
    ->name('admin.security.access');

/*
| There is no security/sessions screen.
|
| It was built and then removed the same day. The user detail page already
| listed a person's open sessions and could close them — and that is where
| the question gets asked. Nobody investigating "somebody is in my account"
| opens a list of every session on the site to go looking; they open that
| person's page. A global list is mostly rows saying "Not signed in", which
| is every anonymous visitor, and answers nothing.
|
| Same rule that dropped the email log: what concerns one person belongs on
| that person's page.
*/

Route::livewire('admin/security/abuse', 'pages::admin.security.abuse')
    ->middleware('auth')
    ->name('admin.security.abuse');

/*
| Settings. One route per screen rather than one screen with tabs: a tab is
| a URL you cannot link somebody to, and "the setting is in Admin → Settings
| → General" is an instruction that should be a link.
*/
Route::livewire('admin/settings/general', 'pages::admin.settings.general')
    ->middleware('auth')
    ->name('admin.settings.general');

Route::livewire('admin/settings/homepage', 'pages::admin.settings.homepage')
    ->middleware('auth')
    ->name('admin.settings.homepage');

Route::livewire('admin/settings/security', 'pages::admin.settings.security')
    ->middleware('auth')
    ->name('admin.settings.security');

Route::livewire('admin/settings/email', 'pages::admin.settings.email')
    ->middleware('auth')
    ->name('admin.settings.email');

Route::livewire('admin/settings/appearance', 'pages::admin.settings.appearance')
    ->middleware('auth')
    ->name('admin.settings.appearance');

Route::livewire('admin/settings/ads', 'pages::admin.settings.ads')
    ->middleware('auth')
    ->name('admin.settings.ads');

Route::livewire('admin/settings/code', 'pages::admin.settings.code')
    ->middleware('auth')
    ->name('admin.settings.code');

Route::livewire('admin/settings/downloads', 'pages::admin.settings.downloads')
    ->middleware('auth')
    ->name('admin.settings.downloads');

/*
| Records for the ⌘K palette.
|
| SCREENS ARE NOT SERVED HERE. AdminNav::searchable() ships the whole screen
| list into the page, so "where is the ad setting" is answered in the browser
| before the word is finished — and stays answered when this endpoint is slow
| or when the database is the thing being investigated. This route only
| carries what has to be queried.
*/
Route::get('admin/palette', \App\Http\Controllers\AdminPaletteController::class)
    ->middleware(['auth', 'throttle:search'])
    ->name('admin.palette');

/*
| Signing in with Google.
|
| Guest-only: an already signed-in visitor following this link would be sent
| to Google and back to be logged in as somebody they already are — or, worse,
| as somebody else, on a shared machine.
|
| The callback address is derived from this route name and shown on the
| settings screen to be copied into Google Cloud Console. It is never stored:
| APP_URL already decides it, and a second copy would disagree in silence.
*/
Route::middleware('guest')->group(function () {
    Route::get('auth/google/redirect', [\App\Http\Controllers\GoogleAuthController::class, 'redirect'])
        ->name('auth.google.redirect');

    Route::get('auth/google/callback', [\App\Http\Controllers\GoogleAuthController::class, 'callback'])
        ->name('auth.google.callback');
});

Route::livewire('admin/redirects', 'pages::admin.redirects')
    ->middleware('auth')
    ->name('admin.redirects');

Route::livewire('admin/subscribers', 'pages::admin.subscribers')
    ->middleware('auth')
    ->name('admin.subscribers');

Route::livewire('admin/campaigns', 'pages::admin.campaigns')
    ->middleware('auth')
    ->name('admin.campaigns');

Route::livewire('admin/campaigns/create', 'pages::admin.campaigns.edit')
    ->middleware('auth')
    ->name('admin.campaigns.create');

Route::livewire('admin/campaigns/{campaign:id}/edit', 'pages::admin.campaigns.edit')
    ->middleware('auth')
    ->name('admin.campaigns.edit');

Route::livewire('admin/search', 'pages::admin.search')
    ->middleware('auth')
    ->name('admin.search');

Route::livewire('admin/blog-categories', 'pages::admin.post-categories')
    ->middleware('auth')
    ->name('admin.post-categories');

Route::livewire('admin/blog-tags', 'pages::admin.post-tags')
    ->middleware('auth')
    ->name('admin.post-tags');

Route::livewire('admin/contributors', 'pages::admin.contributors')
    ->middleware('auth')
    ->name('admin.contributors');

Route::livewire('admin/claims', 'pages::admin.claims')
    ->middleware('auth')
    ->name('admin.claims');

Route::livewire('admin/licenses', 'pages::admin.licenses')
    ->middleware('auth')
    ->name('admin.licenses');

Route::livewire('admin/packs', 'pages::admin.packs')
    ->middleware('auth')
    ->name('admin.packs');

Route::livewire('admin/packs/{pack}', 'pages::admin.packs.edit')
    ->middleware('auth')
    ->name('admin.packs.edit');

Route::livewire('moderate', 'pages::moderate')
    ->middleware('auth')
    ->name('moderate');

Route::livewire('users', 'pages::users')
    ->middleware('auth')
    ->name('users');

/*
| There is no /dashboard.
|
| It was the Laravel starter kit's placeholder — a static view of three grey
| boxes — and because config/fortify.php still pointed 'home' at it, every
| login landed on an empty page while the real panel sat at /admin. Two
| addresses, one of them dead.
|
| The admin panel is /admin (route name admin.dashboard). A signed-in
| visitor who is not staff belongs in /library. If a user dashboard is ever
| built — their uploads and where each one stands, downloads this month
| against their plan, account details — it gets its own route then, with
| something on it.
*/

/*
| Legal. Plain Blade pages: no state, no interactivity, and they must stay
| readable even if JavaScript fails.
*/
/*
| The blog. The static segments are registered before blog/{post} so a post
| can never be shadowed by — or shadow — a category or tag listing.
*/
Route::get('blog/feed', \App\Http\Controllers\FeedController::class)->name('blog.feed');
Route::livewire('blog', 'pages::blog')->name('blog');
Route::livewire('blog/category/{category}', 'pages::blog')->name('blog.category');
Route::livewire('blog/tag/{tag}', 'pages::blog')->name('blog.tag');
Route::livewire('blog/{post}', 'pages::blog.show')->name('blog.show');

/*
| Unsubscribe. Both verbs on purpose: Gmail's one-click button POSTs here
| and never loads the page, and honouring that POST is what keeps people
| pressing "unsubscribe" instead of "report spam".
*/
Route::livewire('unsubscribe/{token}', 'pages::unsubscribe')
    ->name('newsletter.unsubscribe');

Route::post('unsubscribe/{token}', \App\Http\Controllers\OneClickUnsubscribeController::class)
    ->name('newsletter.unsubscribe.post');

/*
| The copyright complaint form. Public and unauthenticated on purpose:
| the people who need it are almost never dbelo users.
*/
Route::livewire('copyright', 'pages::copyright')->name('claims.create');

Route::controller(LegalController::class)->group(function () {
    Route::get('terms', 'terms')->name('legal.terms');
    Route::get('privacy', 'privacy')->name('legal.privacy');
    Route::get('licenses', 'licenses')->name('legal.licenses');
    Route::get('contributors', 'contributor')->name('legal.contributor');
});

require __DIR__.'/settings.php';

/*
|--------------------------------------------------------------------------
| Pages, at the root of the site
|--------------------------------------------------------------------------
|
| /about, /pricing — no prefix, so they read as part of the site rather than
| as something bolted on.
|
| THIS MUST STAY THE LAST ROUTE IN THE FILE. Laravel matches in registration
| order, so anything below it would never be reached. App\Support\ReservedSlugs
| stops a page from being given a slug that shadows a real section.
|
*/
Route::livewire('{page}', 'pages::page')
    ->where('page', '[a-z0-9]+(?:-[a-z0-9]+)*')
    ->name('pages.show');
