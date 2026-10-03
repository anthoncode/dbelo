<?php

use App\Models\Post;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.site')] class extends Component {
    public Post $post;

    /** Visible to the admin before it is published: this is the preview. */
    public bool $isDraft = false;

    /**
     * The slug, from the route parameter or from the route's NAME.
     *
     * Most pages arrive through the {page} catch-all and bring their slug
     * with them. The three legal pages have named routes of their own —
     * /terms, /privacy, /contributors — with no parameter at all, because
     * route('legal.terms') is used in a dozen places and renaming it would
     * have been a dozen chances to miss one. App\Support\Legal::ROUTES maps
     * those names onto slugs, so there is one list and the routes carry no
     * duplicated knowledge of it.
     */
    public function mount(?string $page = null): void
    {
        $page ??= \App\Support\Legal::ROUTES[request()->route()?->getName()] ?? null;

        abort_unless($page, 404);

        // This route is the last one registered and catches everything the
        // real routes did not. A slug that matches no page has to 404 here,
        // or the site would answer 200 for any address at all.
        $this->post = Post::pages()
            ->where('slug', $page)
            // An admin sees drafts, so the "Open" link in the editor shows
            // the real page instead of a 404 before publishing.
            ->unless(auth()->user()?->isAdmin(), fn ($q) => $q->live())
            ->firstOrFail();

        $this->isDraft = ! $this->post->isLive();

        $this->post->loadMissing('cover');

        view()->share('seo', [
            'title' => $this->post->meta_title ?: $this->post->title,
            'description' => $this->post->meta_description ?: $this->post->summary(),
            'canonical' => $this->post->url(),
            'image' => $this->post->cover?->url() ?? asset('og-default.png'),
            'type' => 'article',
            'noindex' => $this->post->noindex || $this->isDraft,
        ]);
    }

    public function title(): string
    {
        return $this->post->title;
    }
}; ?>

<div>
    <article class="mx-auto max-w-3xl py-6">

        @if ($isDraft)
            <div class="mb-7 flex flex-wrap items-center gap-3 rounded-card bg-action px-5 py-3.5 text-white">
                <x-icon name="eye" style="solid" class="text-sm" />
                <span class="text-[0.88rem]">
                    {{ $post->isScheduled() ? 'Scheduled for '.$post->published_at->format('M j, Y · H:i') : 'Draft' }} —
                    only you can see this page.
                </span>
                <a href="{{ route('admin.pages.edit', $post) }}" wire:navigate
                   class="ml-auto flex items-center gap-2 rounded-full bg-white/20 px-4 py-1.5 text-[0.8rem] font-medium transition hover:bg-white/30">
                    <x-icon name="pen-to-square" style="solid" class="text-[10px]" />
                    Back to the editor
                </a>
            </div>
        @endif

        <header class="mb-9">
            {{-- ── THE LEGAL FRAMING ─────────────────────────────────────
                 Kept from the Blade shell these pages used to live in. The
                 effective date comes from config, not from updated_at: a
                 typo fixed on a Tuesday is not a new version of an
                 agreement, and moving the date on every edit would make the
                 one date with legal meaning meaningless. --}}
            @if ($post->is_system)
                <div class="micro">{{ __('Legal') }}</div>
            @endif

            <h1 class="mt-2 text-4xl font-semibold tracking-[-0.03em]">{{ $post->title }}</h1>

            @if ($post->excerpt)
                <p class="mt-3 text-lg leading-relaxed text-ink/55 dark:text-paper/55">{{ $post->excerpt }}</p>
            @endif

            @if ($post->is_system && ($effective = \App\Support\Legal::effectiveDate()))
                <p class="micro mt-3">{{ __('Effective') }} {{ $effective->format('F j, Y') }}</p>
            @endif
        </header>

        @if ($post->cover)
            <img src="{{ $post->cover->url() }}" srcset="{{ $post->cover->srcset() }}"
                 sizes="(min-width: 768px) 768px, 100vw"
                 alt="{{ $post->cover->alt }}"
                 class="mb-10 aspect-[16/9] w-full rounded-panel object-cover shadow-soft-lg" />
        @endif

        <div class="rounded-card bg-surface p-8 shadow-soft-md dark:bg-surface-dark sm:p-10">
            {{-- Legal::fill() runs on the OUTPUT of html(), never before it.
                 html() caches the parsed markdown for ever and drops it when
                 the post is saved; the company name and address are not in
                 the post at all, they are in config. Filling them first
                 would bake a stale entity name into a cache nothing
                 invalidates. --}}
            <x-prose>{!! \App\Support\Legal::fill($post->html()) !!}</x-prose>
        </div>

        @if ($post->is_system)
            {{-- The other legal pages, as the old shell listed them. Whoever
                 reads one of these is usually checking a second. --}}
            <div class="mt-8 flex flex-wrap gap-3">
                @foreach ([
                    ['legal.terms', __('Terms')],
                    ['legal.privacy', __('Privacy')],
                    ['legal.licenses', __('Licenses')],
                    ['legal.contributor', __('Contributors')],
                    ['claims.create', __('Copyright')],
                ] as [$name, $label])
                    <a href="{{ route($name) }}" wire:navigate
                       @class([
                           'rounded-full px-5 py-2.5 text-[0.85rem] shadow-soft-sm transition duration-300 ease-dbelo hover:-translate-y-0.5',
                           'bg-ink text-paper dark:bg-brand' => request()->routeIs($name),
                           'bg-surface dark:bg-surface-dark' => ! request()->routeIs($name),
                       ])>{{ $label }}</a>
                @endforeach
            </div>
        @endif

        <p class="mt-8 text-center text-[0.8rem] text-ink/30 dark:text-paper/25">
            Last updated {{ $post->updated_at->format('F j, Y') }}
        </p>
    </article>
</div>
