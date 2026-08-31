<?php

use App\Models\Post;
use App\Models\PostCategory;
use App\Models\PostTag;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.site')] #[Title('Blog')] class extends Component {
    use WithPagination;

    // Filled from the path, never the query string: /blog/category/tutorials
    // is the canonical URL, and a ?category= duplicate of it would compete
    // with itself in search results.
    public string $category = '';
    public string $tag = '';

    public function mount(?string $category = null, ?string $tag = null): void
    {
        $this->category = $category ?? '';
        $this->tag = $tag ?? '';

        $heading = $this->heading();

        view()->share('seo', [
            'title' => $heading,
            'description' => $this->current?->description
                ?: 'Guides, techniques and news about sound design, field recording and working with audio.',
            'canonical' => url()->current(),
            // A filtered listing is the same content sliced differently.
            // Letting Google index every slice splits the ranking.
            'noindex' => (bool) ($this->category || $this->tag),
        ]);
    }

    #[Computed]
    public function current(): ?PostCategory
    {
        return $this->category ? PostCategory::where('slug', $this->category)->first() : null;
    }

    protected function heading(): string
    {
        if ($this->current) {
            return $this->current->name;
        }

        if ($this->tag) {
            return '#'.$this->tag;
        }

        return 'Blog';
    }

    #[Computed]
    public function posts()
    {
        return Post::posts()
            ->live()
            ->with(['category:id,name,slug', 'cover:id,disk,path,alt,width,height,variants', 'author:id,name'])
            ->when($this->category, fn ($q, $c) => $q->whereHas('category', fn ($x) => $x->where('slug', $c)))
            ->when($this->tag, fn ($q, $t) => $q->whereHas('tags', fn ($x) => $x->where('slug', $t)))
            ->latest('published_at')
            ->paginate(9);
    }

    #[Computed]
    public function categories()
    {
        return PostCategory::whereHas('posts', fn ($q) => $q->live())
            ->orderBy('sort_order')->orderBy('name')->get();
    }

    #[Computed]
    public function tags()
    {
        return PostTag::whereHas('posts', fn ($q) => $q->live())
            ->withCount(['posts' => fn ($q) => $q->live()])
            ->orderByDesc('posts_count')
            ->limit(14)
            ->get();
    }
}; ?>

<div>
    <div class="mx-auto max-w-[1160px]">

        <header class="py-10">
            <div class="micro">Blog</div>
            <h1 class="mt-2 text-4xl font-semibold tracking-[-0.03em]">
                @if ($this->current)
                    {{ $this->current->name }}
                @elseif ($tag)
                    <span class="text-brand">#</span>{{ $tag }}
                @else
                    Notes on sound
                @endif
            </h1>
            <p class="mt-3 max-w-xl leading-relaxed text-ink/55 dark:text-paper/55">
                {{ $this->current?->description ?: 'Guides, techniques and news about sound design, field recording and working with audio.' }}
            </p>
        </header>

        {{-- Filters --}}
        @if ($this->categories->isNotEmpty())
            <div class="mb-8 flex flex-wrap gap-2">
                <a href="{{ route('blog') }}" wire:navigate
                   @class([
                       'rounded-full px-4 py-2 text-sm transition duration-300 ease-dbelo',
                       'bg-ink text-paper shadow-soft-sm dark:bg-brand' => ! $category && ! $tag,
                       'bg-surface text-ink/60 shadow-soft-sm hover:-translate-y-0.5 dark:bg-surface-dark dark:text-paper/60' => $category || $tag,
                   ])>All</a>

                @foreach ($this->categories as $item)
                    <a href="{{ route('blog.category', $item->slug) }}" wire:navigate
                       @class([
                           'rounded-full px-4 py-2 text-sm transition duration-300 ease-dbelo',
                           'bg-ink text-paper shadow-soft-sm dark:bg-brand' => $category === $item->slug,
                           'bg-surface text-ink/60 shadow-soft-sm hover:-translate-y-0.5 dark:bg-surface-dark dark:text-paper/60' => $category !== $item->slug,
                       ])>{{ $item->name }}</a>
                @endforeach
            </div>
        @endif

        {{-- Posts --}}
        @if ($this->posts->isEmpty())
            <div class="rounded-card bg-surface py-20 text-center shadow-soft-md dark:bg-surface-dark">
                <x-icon name="newspaper" style="regular" class="text-[24px] text-ink/20 dark:text-paper/20" />
                <p class="mt-3 text-ink/45 dark:text-paper/45">Nothing published here yet.</p>
            </div>
        @else
            <div class="grid gap-7 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($this->posts as $post)
                    <article wire:key="post-{{ $post->id }}"
                             class="group flex flex-col overflow-hidden rounded-card bg-surface shadow-soft-md transition duration-350 ease-dbelo hover:-translate-y-1 hover:shadow-soft-lg dark:bg-surface-dark">

                        <a href="{{ $post->url() }}" wire:navigate class="block overflow-hidden">
                            @if ($post->cover)
                                <img src="{{ $post->cover->url() }}" srcset="{{ $post->cover->srcset() }}"
                                     sizes="(min-width: 1024px) 360px, (min-width: 640px) 50vw, 100vw"
                                     alt="{{ $post->cover->alt }}" loading="lazy"
                                     class="aspect-[16/10] w-full object-cover transition duration-500 ease-dbelo group-hover:scale-[1.03]" />
                            @else
                                <div class="grid aspect-[16/10] w-full place-items-center bg-ink/[0.04] dark:bg-paper/5">
                                    <x-icon name="waveform-lines" style="solid" class="text-[26px] text-brand/25" />
                                </div>
                            @endif
                        </a>

                        <div class="flex flex-1 flex-col p-6">
                            @if ($post->category)
                                <a href="{{ route('blog.category', $post->category->slug) }}" wire:navigate
                                   class="micro !text-brand mb-2 self-start">{{ $post->category->name }}</a>
                            @endif

                            <h2 class="text-lg font-medium leading-snug">
                                <a href="{{ $post->url() }}" wire:navigate class="transition hover:text-brand">{{ $post->title }}</a>
                            </h2>

                            <p class="mt-2.5 flex-1 text-[0.9rem] leading-relaxed text-ink/55 dark:text-paper/55">
                                {{ $post->summary(120) }}
                            </p>

                            <div class="mt-5 flex items-center gap-2 text-[0.78rem] text-ink/35 dark:text-paper/30">
                                <span>{{ $post->published_at->format('M j, Y') }}</span>
                                <span>·</span>
                                <span>{{ $post->reading_minutes }} min read</span>
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>

            @if ($this->posts->hasPages())
                <div class="mt-12 flex items-center justify-center gap-2">
                    <button wire:click="previousPage" @disabled($this->posts->onFirstPage())
                            class="grid size-10 place-items-center rounded-full bg-surface shadow-soft-sm transition duration-300 ease-dbelo hover:-translate-y-0.5 disabled:opacity-30 disabled:hover:translate-y-0 dark:bg-surface-dark">
                        <x-icon name="chevron-left" style="solid" class="text-[12px]" />
                    </button>

                    @foreach ($this->posts->getUrlRange(max(1, $this->posts->currentPage() - 2), min($this->posts->lastPage(), $this->posts->currentPage() + 2)) as $page => $url)
                        <button wire:click="gotoPage({{ $page }})" wire:key="bpg-{{ $page }}"
                                @class([
                                    'grid size-10 place-items-center rounded-full text-sm tabular-nums transition duration-300 ease-dbelo',
                                    'bg-ink text-paper shadow-soft-sm dark:bg-brand' => $page === $this->posts->currentPage(),
                                    'bg-surface text-ink/55 shadow-soft-sm hover:-translate-y-0.5 dark:bg-surface-dark dark:text-paper/55' => $page !== $this->posts->currentPage(),
                                ])>{{ $page }}</button>
                    @endforeach

                    <button wire:click="nextPage" @disabled(! $this->posts->hasMorePages())
                            class="grid size-10 place-items-center rounded-full bg-surface shadow-soft-sm transition duration-300 ease-dbelo hover:-translate-y-0.5 disabled:opacity-30 disabled:hover:translate-y-0 dark:bg-surface-dark">
                        <x-icon name="chevron-right" style="solid" class="text-[12px]" />
                    </button>
                </div>
            @endif
        @endif

        {{-- Tags --}}
        @if ($this->tags->isNotEmpty())
            <div class="mt-14 border-t border-ink/[0.07] pt-8 dark:border-paper/10">
                <div class="micro mb-4">Browse by tag</div>
                <div class="flex flex-wrap gap-2">
                    @foreach ($this->tags as $item)
                        <a href="{{ route('blog.tag', $item->slug) }}" wire:navigate
                           @class([
                               'rounded-full px-4 py-2 text-sm shadow-soft-sm transition duration-300 ease-dbelo hover:-translate-y-0.5',
                               'bg-brand text-white' => $tag === $item->slug,
                               'bg-surface text-ink/60 dark:bg-surface-dark dark:text-paper/60' => $tag !== $item->slug,
                           ])>
                            {{ $item->name }}
                            <span class="ml-1 opacity-40">{{ $item->posts_count }}</span>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</div>
