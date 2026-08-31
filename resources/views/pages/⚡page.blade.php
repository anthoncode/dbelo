<?php

use App\Models\Post;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.site')] class extends Component {
    public Post $post;

    /** Visible to the admin before it is published: this is the preview. */
    public bool $isDraft = false;

    public function mount(string $page): void
    {
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
            <h1 class="text-4xl font-semibold tracking-[-0.03em]">{{ $post->title }}</h1>

            @if ($post->excerpt)
                <p class="mt-3 text-lg leading-relaxed text-ink/55 dark:text-paper/55">{{ $post->excerpt }}</p>
            @endif
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

        <p class="mt-8 text-center text-[0.8rem] text-ink/30 dark:text-paper/25">
            Last updated {{ $post->updated_at->format('F j, Y') }}
        </p>
    </article>
</div>
