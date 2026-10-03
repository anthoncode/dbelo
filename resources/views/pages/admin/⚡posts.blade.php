<?php

use App\Models\ActivityLog;
use App\Models\Post;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.admin')] #[Title('Content')] class extends Component {
    use WithPagination;

    public string $type = Post::TYPE_POST;

    #[Url(as: 'q', except: '')] public string $search = '';
    #[Url(except: '')] public string $status = '';
    #[Url(except: '')] public string $category = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $this->type = request()->routeIs('admin.pages') ? Post::TYPE_PAGE : Post::TYPE_POST;
    }

    public function updated(string $property): void
    {
        if ($property !== 'page') {
            $this->resetPage();
        }
    }

    #[Computed]
    public function isBlog(): bool
    {
        return $this->type === Post::TYPE_POST;
    }

    #[Computed]
    public function posts()
    {
        return Post::query()
            ->where('type', $this->type)
            ->with(['category:id,name', 'cover:id,disk,path,alt', 'author:id,name'])
            ->when($this->search, fn ($q, $s) => $q->where('title', 'like', "%{$s}%"))
            ->when($this->status === 'live', fn ($q) => $q->live())
            ->when($this->status === 'draft', fn ($q) => $q->where('status', Post::STATUS_DRAFT))
            ->when($this->status === 'scheduled', fn ($q) => $q
                ->where('status', Post::STATUS_PUBLISHED)->where('published_at', '>', now()))
            ->when($this->category, fn ($q, $c) => $q->where('post_category_id', $c))
            ->tap(fn ($q) => $this->isBlog
                ? $q->orderByRaw('COALESCE(published_at, updated_at) DESC')
                : $q->orderBy('sort_order')->orderBy('title'))
            ->paginate(10);
    }

    /* ── The footer column, on the pages screen ───────────────────────── */

    /**
     * The ids the footer is drawing, and how many asked to be.
     *
     * Both come from Post, which is where the rule lives. Rewriting the
     * query here would give this screen its own opinion about what is in the
     * footer, and the day the rule changed only one of the two would know.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function footerIds(): array
    {
        return $this->isBlog ? [] : Post::footerPageIds();
    }

    #[Computed]
    public function footerWanted(): int
    {
        return $this->isBlog ? 0 : Post::footerPagesWanted();
    }

    #[Computed]
    public function categoryOptions()
    {
        return \App\Models\PostCategory::orderBy('sort_order')->orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function counts(): array
    {
        $base = fn () => Post::where('type', $this->type);

        return [
            'live' => $base()->live()->count(),
            'draft' => $base()->where('status', Post::STATUS_DRAFT)->count(),
            'scheduled' => $base()->where('status', Post::STATUS_PUBLISHED)->where('published_at', '>', now())->count(),
        ];
    }

    // ── Posts ────────────────────────────────────────────────────────

    public function trash(int $id): void
    {
        $post = Post::findOrFail($id);

        /*
         * The site needs Terms, Privacy and the Contributor Agreement: the
         * footer links them, the sitemap lists them, and each one links to
         * the others. The button is not drawn for these, and this is the
         * rule behind the button — a hidden control is a control anybody
         * can still call.
         */
        if ($post->is_system) {
            session()->flash('error', "“{$post->title}” is a page the site needs. It can be edited, not deleted.");

            return;
        }

        $post->delete();

        ActivityLog::record('post.deleted', $post, ucfirst($this->type).": {$post->title} deleted");

        unset($this->posts, $this->counts, $this->footerIds, $this->footerWanted);
        session()->flash('ok', "“{$post->title}” deleted.");
    }

    public function duplicate(int $id): void
    {
        $post = Post::with('tags')->findOrFail($id);

        $copy = $post->replicate(['views_count', 'published_at', 'autosaved_at']);
        $copy->title = $post->title.' (copy)';
        $copy->slug = Post::uniqueSlug($post->title.' copy', $post->type);
        $copy->status = Post::STATUS_DRAFT;
        $copy->published_at = null;
        // Copying the flag would make a second undeletable page out of one
        // click, and the copy is not the one anything links to.
        $copy->is_system = false;
        $copy->save();

        $copy->tags()->sync($post->tags->pluck('id'));

        unset($this->posts, $this->counts, $this->footerIds, $this->footerWanted);

        $this->redirectRoute($this->isBlog ? 'admin.blog.edit' : 'admin.pages.edit', $copy, navigate: true);
    }

}; ?>

<div>
    @if (session('ok'))
        <div class="mb-5 flex items-center gap-3 rounded-xl border border-success/30 bg-success/10 px-4 py-3">
            <x-icon name="circle-check" style="solid" class="text-success" />
            <span class="text-[0.88rem]">{{ session('ok') }}</span>
        </div>
    @endif

    @if (session('error'))
        <div class="mb-5 flex items-center gap-3 rounded-xl border border-danger/30 bg-danger/10 px-4 py-3">
            <x-icon name="triangle-exclamation" style="solid" class="text-danger" />
            <span class="text-[0.88rem]">{{ session('error') }}</span>
        </div>
    @endif

    <div class="min-w-0 rounded-2xl border border-hairline bg-panel">

        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-hairline px-5 py-3.5">
            <h2 class="text-[0.95rem] font-medium">
                {{ $this->isBlog ? 'Posts' : 'Pages' }}
                <span class="ml-1.5 text-paper/35">{{ $this->posts->total() }}</span>
            </h2>

            <div class="flex flex-wrap items-center gap-2">
                <div class="flex items-center gap-2.5 rounded-lg bg-raised px-3 py-2">
                    <x-icon name="magnifying-glass" style="regular" class="text-[12px] text-paper/35" />
                    <input type="search" wire:model.live.debounce.300ms="search" placeholder="Title…"
                           class="w-36 border-0 bg-transparent p-0 text-[0.83rem] text-paper placeholder:text-paper/30 focus:outline-none focus:ring-0" />
                </div>

                @if ($this->isBlog && $this->categoryOptions->isNotEmpty())
                    <select wire:model.live="category"
                            class="rounded-lg border-0 bg-raised px-3 py-2 text-[0.82rem] text-paper focus:outline-none focus:ring-2 focus:ring-brand/40">
                        <option value="">Any category</option>
                        @foreach ($this->categoryOptions as $option)
                            <option value="{{ $option->id }}">{{ $option->name }}</option>
                        @endforeach
                    </select>
                @endif

                <select wire:model.live="status"
                        class="rounded-lg border-0 bg-raised px-3 py-2 text-[0.82rem] text-paper focus:outline-none focus:ring-2 focus:ring-brand/40">
                    <option value="">All</option>
                    <option value="live">Live ({{ $this->counts['live'] }})</option>
                    <option value="draft">Drafts ({{ $this->counts['draft'] }})</option>
                    <option value="scheduled">Scheduled ({{ $this->counts['scheduled'] }})</option>
                </select>

                <a href="{{ route($this->isBlog ? 'admin.blog.create' : 'admin.pages.create') }}" wire:navigate
                   class="flex items-center gap-2 rounded-lg bg-action px-4 py-2.5 text-[0.83rem] font-medium text-white transition hover:brightness-110">
                    <x-icon name="plus" style="solid" class="text-[11px]" />
                    {{ $this->isBlog ? 'New post' : 'New page' }}
                </a>
            </div>
        </div>

        {{-- ── MORE PAGES WANT THE FOOTER THAN FIT ──────────────────────
             Only drawn when it is true, and it names the number rather than
             saying "some". The alternative is a tick box that silently does
             nothing on two rows and no way to find out which two. --}}
        @unless ($this->isBlog)
            @if ($this->footerWanted > \App\Models\Post::FOOTER_MAX)
                <div class="mx-5 mb-4 flex flex-wrap items-center gap-3 rounded-xl bg-warning/[0.08] px-4 py-3 text-[0.8rem] text-warning">
                    <x-icon name="triangle-exclamation" style="solid" class="text-[0.8rem]" />
                    <span>
                        {{ $this->footerWanted }} pages are ticked for the footer and it draws
                        {{ \App\Models\Post::FOOTER_MAX }}. The ones marked
                        <span class="font-medium">not shown</span> below are live, in the sitemap, and not in the footer.
                    </span>
                </div>
            @endif
        @endunless

        <table class="w-full text-left">
            <thead>
                <tr class="border-b border-hairline text-[0.68rem] uppercase tracking-[0.13em] text-paper/30">
                    <th class="w-12 px-5 py-2.5 font-medium">#</th>
                    <th class="px-3 py-2.5 font-medium">Title</th>
                    @if ($this->isBlog)
                        <th class="w-32 px-3 py-2.5 font-medium">Category</th>
                    @else
                        <th class="w-40 px-3 py-2.5 font-medium">URL</th>
                    @endif
                    <th class="w-28 px-3 py-2.5 font-medium">Status</th>
                    <th class="w-28 px-3 py-2.5 font-medium">{{ $this->isBlog ? 'Published' : 'Updated' }}</th>
                    <th class="w-[120px] px-5 py-2.5 text-right font-medium">Actions</th>
                </tr>
            </thead>

            <tbody class="divide-y divide-hairline">
                @php $row = ($this->posts->currentPage() - 1) * $this->posts->perPage(); @endphp

                @forelse ($this->posts as $post)
                    @php $row++; @endphp

                    <tr wire:key="p-{{ $post->id }}" class="transition hover:bg-paper/[0.03]">
                        <td class="px-5 py-3 text-[0.8rem] tabular-nums text-paper/25">{{ $row }}</td>

                        <td class="px-3 py-3">
                            <div class="flex items-center gap-3">
                                @if ($post->cover)
                                    <img src="{{ $post->cover->url() }}" alt="" loading="lazy"
                                         class="size-10 shrink-0 rounded-lg object-cover" />
                                @else
                                    <span class="grid size-10 shrink-0 place-items-center rounded-lg bg-raised text-paper/20">
                                        <x-icon name="{{ $this->isBlog ? 'newspaper' : 'file' }}" style="solid" class="text-[12px]" />
                                    </span>
                                @endif

                                <div class="min-w-0">
                                    <a href="{{ route($this->isBlog ? 'admin.blog.edit' : 'admin.pages.edit', $post) }}" wire:navigate
                                       class="block truncate text-[0.89rem] transition hover:text-brand">{{ $post->title }}</a>
                                    <div class="truncate text-[0.72rem] text-paper/25">
                                        {{ $post->reading_minutes }} min
                                        @if ($post->author) · {{ $post->author->name }} @endif
                                        @if ($post->views_count) · {{ number_format($post->views_count) }} views @endif
                                    </div>
                                </div>
                            </div>
                        </td>

                        @if ($this->isBlog)
                            <td class="px-3 py-3">
                                @if ($post->category)
                                    <span class="rounded-full bg-raised px-2.5 py-1 text-[0.72rem] text-paper/60">{{ $post->category->name }}</span>
                                @else
                                    <span class="text-[0.75rem] text-paper/20">—</span>
                                @endif
                            </td>
                        @else
                            <td class="px-3 py-3">
                                <div class="font-mono text-[0.75rem] text-paper/40">/{{ $post->slug }}</div>

                                {{-- Whether the footer is actually drawing
                                     this page, read from the same list the
                                     footer itself renders — not from a
                                     second copy of the rule. --}}
                                @if ($post->in_footer)
                                    @if (in_array($post->id, $this->footerIds, true))
                                        <span class="mt-1.5 inline-flex items-center gap-1.5 rounded-full bg-raised px-2 py-0.5 text-[0.68rem] text-paper/45">
                                            <x-icon name="anchor" style="solid" class="text-[0.6rem]" />
                                            Footer · {{ $post->sort_order }}
                                        </span>
                                    @else
                                        <span class="group/tip relative mt-1.5 inline-flex items-center gap-1.5 rounded-full bg-warning/15 px-2 py-0.5 text-[0.68rem] text-warning">
                                            <x-icon name="eye-slash" style="solid" class="text-[0.6rem]" />
                                            Footer · not shown
                                            <span class="pointer-events-none absolute bottom-full left-0 z-50 mb-2 whitespace-nowrap rounded-lg bg-paper px-2.5 py-1 text-[0.7rem] font-medium text-ink opacity-0 shadow-xl transition group-hover/tip:opacity-100">
                                                @if (! $post->isLive())
                                                    Not published yet
                                                @else
                                                    Past the first {{ \App\Models\Post::FOOTER_MAX }}
                                                @endif
                                            </span>
                                        </span>
                                    @endif
                                @endif
                            </td>
                        @endif

                        <td class="px-3 py-3">
                            @if ($post->isLive())
                                <span class="rounded-full bg-success/15 px-2.5 py-1 text-[0.72rem] text-success">Live</span>
                            @elseif ($post->isScheduled())
                                <span class="group/tip relative inline-flex rounded-full bg-info/15 px-2.5 py-1 text-[0.72rem] text-info">
                                    Scheduled
                                    <span class="pointer-events-none absolute bottom-full left-0 z-50 mb-2 whitespace-nowrap rounded-lg bg-paper px-2.5 py-1 text-[0.7rem] font-medium text-ink opacity-0 shadow-xl transition group-hover/tip:opacity-100">
                                        {{ $post->published_at->format('M j, Y · H:i') }}
                                    </span>
                                </span>
                            @else
                                <span class="rounded-full bg-raised px-2.5 py-1 text-[0.72rem] text-paper/45">Draft</span>
                            @endif
                        </td>

                        <td class="px-3 py-3 text-[0.78rem] text-paper/40">
                            {{ ($this->isBlog ? $post->published_at : $post->updated_at)?->format('M j, Y') ?? '—' }}
                        </td>

                        <td class="px-5 py-3">
                            <div class="flex items-center justify-end gap-1">
                                @if ($post->isLive())
                                    <a href="{{ $post->url() }}" target="_blank">
                                        <x-admin.icon-button icon="arrow-up-right-from-square" label="Open on the site" />
                                    </a>
                                @endif

                                <a href="{{ route($this->isBlog ? 'admin.blog.edit' : 'admin.pages.edit', $post) }}" wire:navigate>
                                    <x-admin.icon-button icon="pen-to-square" label="Edit" />
                                </a>

                                <x-admin.icon-button icon="copy" label="Duplicate" wire:click="duplicate({{ $post->id }})" />

                                @if ($post->is_system)
                                    <span class="group/tip relative grid size-9 place-items-center text-paper/20"
                                          aria-label="This page cannot be deleted">
                                        <x-icon name="lock" style="solid" class="text-[0.78rem]" />
                                        <span class="pointer-events-none absolute bottom-full right-0 z-50 mb-2 whitespace-nowrap rounded-lg bg-paper px-2.5 py-1 text-[0.7rem] font-medium text-ink opacity-0 shadow-xl transition group-hover/tip:opacity-100">
                                            The site needs this one
                                        </span>
                                    </span>
                                @else
                                    <x-admin.icon-button icon="trash" label="Delete"
                                                         class="hover:!bg-danger/15 hover:!text-danger"
                                                         wire:click="trash({{ $post->id }})"
                                                         wire:confirm="Delete “{{ $post->title }}”?" />
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-5 py-16 text-center">
                            <x-icon name="{{ $this->isBlog ? 'newspaper' : 'file' }}" style="regular" class="text-[24px] text-paper/20" />
                            <p class="mt-3 text-[0.9rem] text-paper/45">
                                {{ $search || $status ? 'Nothing matches these filters' : 'Nothing written yet' }}
                            </p>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        @if ($this->posts->hasPages())
            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-hairline px-5 py-3.5">
                <span class="text-[0.78rem] text-paper/35">
                    {{ $this->posts->firstItem() }}–{{ $this->posts->lastItem() }} of {{ $this->posts->total() }}
                </span>

                <div class="flex items-center gap-1.5">
                    <x-admin.icon-button icon="chevron-left" variant="muted" label="Previous"
                                         wire:click="previousPage" @disabled($this->posts->onFirstPage()) />

                    @foreach ($this->posts->getUrlRange(max(1, $this->posts->currentPage() - 2), min($this->posts->lastPage(), $this->posts->currentPage() + 2)) as $page => $url)
                        <button wire:click="gotoPage({{ $page }})" wire:key="ppg-{{ $page }}"
                                @class([
                                    'grid size-8 place-items-center rounded-full text-[0.78rem] tabular-nums transition duration-200 ease-dbelo',
                                    'bg-brand text-white' => $page === $this->posts->currentPage(),
                                    'text-paper/40 hover:bg-paper/[0.10] hover:text-paper' => $page !== $this->posts->currentPage(),
                                ])>{{ $page }}</button>
                    @endforeach

                    <x-admin.icon-button icon="chevron-right" variant="muted" label="Next"
                                         wire:click="nextPage" @disabled(! $this->posts->hasMorePages()) />
                </div>
            </div>
        @endif
    </div>
</div>
