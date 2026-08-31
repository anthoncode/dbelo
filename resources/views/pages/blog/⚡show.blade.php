<?php

use App\Models\Post;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.site')] class extends Component {
    public Post $post;

    /** Visible to the admin before it is published: this is the preview. */
    public bool $isDraft = false;

    public function mount(string $post): void
    {
        $this->post = Post::posts()
            ->where('slug', $post)
            // An admin sees drafts, so the "Open" link in the editor shows
            // the real page instead of a 404 before publishing.
            ->unless(auth()->user()?->isAdmin(), fn ($q) => $q->live())
            ->firstOrFail();

        $this->isDraft = ! $this->post->isLive();

        $this->post->load(['cover', 'category', 'tags', 'author']);

        // A draft being read by its own author is not a view worth counting.
        if (! $this->isDraft) {
            $this->post->incrementQuietly('views_count');
        }

        $this->shareSeo();
    }

    protected function shareSeo(): void
    {
        $post = $this->post;

        $jsonld = [
            '@context' => 'https://schema.org',
            '@type' => 'BlogPosting',
            'headline' => $post->title,
            'description' => $post->summary(),
            'url' => $post->url(),
            'datePublished' => $post->published_at?->toAtomString(),
            'dateModified' => $post->updated_at?->toAtomString(),
            'wordCount' => str_word_count(strip_tags((string) $post->body)),
            'author' => [
                '@type' => 'Person',
                'name' => $post->author?->name ?? config('app.name'),
            ],
            'publisher' => [
                '@type' => 'Organization',
                'name' => config('app.name', 'dbelo'),
            ],
        ];

        if ($post->cover) {
            $jsonld['image'] = $post->cover->url();
        }

        if ($post->category) {
            $jsonld['articleSection'] = $post->category->name;
        }

        if ($post->tags->isNotEmpty()) {
            $jsonld['keywords'] = $post->tags->pluck('name')->join(', ');
        }

        view()->share('seo', [
            'title' => $post->meta_title ?: $post->title,
            'description' => $post->meta_description ?: $post->summary(),
            'canonical' => $post->url(),
            'image' => $post->cover?->url() ?? asset('og-default.png'),
            'type' => 'article',
            'noindex' => $post->noindex || $this->isDraft,
            'jsonld' => $jsonld,
        ]);
    }

    /** Same category first, then anything recent. Never the post itself. */
    #[Computed]
    public function related()
    {
        return Post::posts()
            ->live()
            ->with(['cover:id,disk,path,alt', 'category:id,name,slug'])
            ->whereKeyNot($this->post->id)
            ->when($this->post->post_category_id, fn ($q, $c) => $q->orderByRaw(
                'CASE WHEN post_category_id = ? THEN 0 ELSE 1 END', [$c]
            ))
            ->latest('published_at')
            ->limit(3)
            ->get();
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
                <a href="{{ route('admin.blog.edit', $post) }}" wire:navigate
                   class="ml-auto flex items-center gap-2 rounded-full bg-white/20 px-4 py-1.5 text-[0.8rem] font-medium transition hover:bg-white/30">
                    <x-icon name="pen-to-square" style="solid" class="text-[10px]" />
                    Back to the editor
                </a>
            </div>
        @endif

        <nav class="mb-6 flex items-center gap-2 text-sm text-ink/50 dark:text-paper/50">
            <a href="{{ route('blog') }}" wire:navigate class="hover:text-brand">Blog</a>
            @if ($post->category)
                <span>/</span>
                <a href="{{ route('blog.category', $post->category->slug) }}" wire:navigate class="hover:text-brand">
                    {{ $post->category->name }}
                </a>
            @endif
        </nav>

        <header class="mb-9">
            <h1 class="text-4xl font-semibold leading-[1.1] tracking-[-0.03em]">{{ $post->title }}</h1>

            @if ($post->excerpt)
                <p class="mt-4 text-lg leading-relaxed text-ink/55 dark:text-paper/55">{{ $post->excerpt }}</p>
            @endif

            <div class="mt-6 flex flex-wrap items-center gap-2.5 text-[0.84rem] text-ink/40 dark:text-paper/35">
                @if ($post->author)
                    <span class="grid size-7 place-items-center rounded-full bg-brand text-[0.65rem] font-semibold text-white">
                        {{ $post->author->initials() }}
                    </span>
                    <span>{{ $post->author->name }}</span>
                    <span>·</span>
                @endif
                <time datetime="{{ $post->published_at->toDateString() }}">{{ $post->published_at->format('F j, Y') }}</time>
                <span>·</span>
                <span>{{ $post->reading_minutes }} min read</span>
            </div>
        </header>

        @if ($post->cover)
            <img src="{{ $post->cover->url() }}" srcset="{{ $post->cover->srcset() }}"
                 sizes="(min-width: 768px) 768px, 100vw"
                 alt="{{ $post->cover->alt }}"
                 class="mb-10 aspect-[16/9] w-full rounded-panel object-cover shadow-soft-lg" />
        @endif

        <div class="rounded-card bg-surface p-8 shadow-soft-md dark:bg-surface-dark sm:p-10">
            <x-prose>{!! $post->html() !!}</x-prose>
        </div>

        @if ($post->tags->isNotEmpty())
            <div class="mt-7 flex flex-wrap gap-2">
                @foreach ($post->tags as $tag)
                    <a href="{{ route('blog.tag', $tag->slug) }}" wire:navigate
                       class="rounded-full bg-surface px-4 py-2 text-sm text-ink/60 shadow-soft-sm transition duration-300 ease-dbelo hover:-translate-y-0.5 hover:shadow-soft-md dark:bg-surface-dark dark:text-paper/60">
                        {{ $tag->name }}
                    </a>
                @endforeach
            </div>
        @endif

        {{-- The whole reason a sound bank keeps a blog: send the reader to
             the catalogue while they are still interested. --}}
        <div class="mt-12 rounded-panel bg-ink p-8 text-paper shadow-soft-lg dark:bg-surface-dark">
            <h2 class="text-xl font-medium">Looking for the sounds themselves?</h2>
            <p class="mt-2 max-w-lg leading-relaxed text-paper/55">
                Thousands of effects, free to listen to, cleared for commercial use.
            </p>
            <a href="{{ route('sounds.index') }}" wire:navigate
               class="mt-6 inline-flex rounded-full bg-brand px-6 py-3 font-medium text-white shadow-brand transition duration-300 ease-dbelo hover:-translate-y-0.5 hover:shadow-brand-lg">
                Browse the catalogue
            </a>
        </div>
    </article>

    @if ($this->related->isNotEmpty())
        <section class="mx-auto mt-14 max-w-[1160px]">
            <div class="micro">Keep reading</div>
            <h2 class="mb-6 mt-2 text-xl font-medium">More from the blog</h2>

            <div class="grid gap-7 sm:grid-cols-3">
                @foreach ($this->related as $other)
                    <a href="{{ $other->url() }}" wire:navigate wire:key="rel-{{ $other->id }}"
                       class="group overflow-hidden rounded-card bg-surface shadow-soft-md transition duration-350 ease-dbelo hover:-translate-y-1 hover:shadow-soft-lg dark:bg-surface-dark">
                        @if ($other->cover)
                            <img src="{{ $other->cover->url() }}" alt="{{ $other->cover->alt }}" loading="lazy"
                                 class="aspect-[16/10] w-full object-cover transition duration-500 ease-dbelo group-hover:scale-[1.03]" />
                        @endif
                        <div class="p-5">
                            <h3 class="text-[0.95rem] font-medium leading-snug transition group-hover:text-brand">{{ $other->title }}</h3>
                            <p class="mt-2 text-[0.8rem] text-ink/35 dark:text-paper/30">
                                {{ $other->published_at->format('M j, Y') }} · {{ $other->reading_minutes }} min
                            </p>
                        </div>
                    </a>
                @endforeach
            </div>
        </section>
    @endif
</div>
