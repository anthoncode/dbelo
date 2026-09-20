<?php

use App\Models\ActivityLog;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostCategory;
use App\Models\PostTag;
use App\Services\MediaLibrary;
use App\Support\ReservedSlugs;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.admin')] #[Title('Editor')] class extends Component {
    use WithFileUploads;

    public ?int $postId = null;
    public string $type = Post::TYPE_POST;

    // ── The document ──
    public string $title = '';
    public string $slug = '';
    public string $excerpt = '';
    public string $body = '';
    public string $status = Post::STATUS_DRAFT;
    public string $publishedAt = '';
    public ?int $categoryId = null;
    public ?int $coverId = null;
    public int $sortOrder = 0;

    /**
     * Pages only: does this one belong in the footer?
     *
     * OFF for a new page, which is the change. Publishing a page used to put
     * it in the footer of every page on the site with nothing to say
     * otherwise — fine for About, wrong for a landing page written for one
     * link in one email.
     */
    public bool $inFooter = false;

    /** Tag names, not ids: they are typed, and rows are made on save. */
    public array $tags = [];
    public string $tagInput = '';

    // ── SEO ──
    public string $metaTitle = '';
    public string $metaDescription = '';
    public bool $noindex = false;

    // ── Editor chrome ──
    public string $tab = 'write';        // write | preview
    public bool $slugTouched = false;
    public ?string $savedAt = null;

    // ── Media modal ──
    public bool $showMedia = false;
    public string $mediaTarget = 'body';   // body | cover
    public string $mediaSearch = '';
    public int $mediaLimit = 12;
    public $upload;

    public function mount(?Post $post = null): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $this->type = request()->routeIs('admin.pages.*') ? Post::TYPE_PAGE : Post::TYPE_POST;

        if ($post?->exists) {
            abort_unless($post->type === $this->type, 404);

            $this->postId = $post->id;
            $this->title = $post->title;
            $this->slug = $post->slug;
            $this->excerpt = (string) $post->excerpt;
            $this->body = (string) $post->body;
            $this->status = $post->status;
            $this->publishedAt = $post->published_at?->format('Y-m-d\TH:i') ?? '';
            $this->categoryId = $post->post_category_id;
            $this->coverId = $post->cover_media_id;
            $this->sortOrder = $post->sort_order;
            $this->inFooter = (bool) $post->in_footer;
            $this->metaTitle = (string) $post->meta_title;
            $this->metaDescription = (string) $post->meta_description;
            $this->noindex = $post->noindex;
            $this->tags = $post->tags->pluck('name')->all();
            $this->slugTouched = true;
        }
    }

    // ---------------------------------------------------------------
    // Derived state
    // ---------------------------------------------------------------

    #[Computed]
    public function post(): ?Post
    {
        return $this->postId ? Post::find($this->postId) : null;
    }

    #[Computed]
    public function categories()
    {
        return PostCategory::orderBy('sort_order')->orderBy('name')->get();
    }

    #[Computed]
    public function cover(): ?Media
    {
        return $this->coverId ? Media::find($this->coverId) : null;
    }

    /* ── The footer, for pages ─────────────────────────────────────────── */

    /** More pages want the footer than the footer will draw. */
    #[Computed]
    public function footerCrowded(): bool
    {
        return Post::footerPagesWanted() > Post::FOOTER_MAX;
    }

    /**
     * What the footer is actually doing with this page, in one sentence.
     *
     * Both numbers come from the SAVED state, never from the form — the
     * footer draws what is in the database, and a note that counted an
     * unticked box as ticked would be describing a page that does not exist
     * yet. Which is also why an unsaved new page gets told to save first
     * rather than given a figure that would change the moment it did.
     */
    #[Computed]
    public function footerNote(): ?string
    {
        if ($this->type !== Post::TYPE_PAGE || ! $this->inFooter) {
            return null;
        }

        if (! $this->postId) {
            return 'Save it to see where it lands.';
        }

        $wanted = Post::footerPagesWanted();

        if ($wanted <= Post::FOOTER_MAX) {
            return $wanted <= 1
                ? null
                : "{$wanted} pages are in the footer. All of them fit.";
        }

        $max = Post::FOOTER_MAX;

        return in_array($this->postId, Post::footerPageIds(), true)
            ? "{$wanted} pages are ticked and the footer draws {$max}. This one is in."
            : "{$wanted} pages are ticked and the footer draws {$max}. This one is NOT being shown — "
                .'give it a lower number, or untick one of the others.';
    }

    /**
     * Rendered by the same call the public page uses, so the preview cannot
     * quietly disagree with what gets published.
     */
    #[Computed]
    public function preview(): string
    {
        return Post::render($this->body);
    }

    /** The address this will have. Live as you type, so the slug is real. */
    #[Computed]
    public function publicUrl(): string
    {
        return url($this->type === Post::TYPE_PAGE ? '/'.$this->slug : '/blog/'.$this->slug);
    }

    #[Computed]
    public function library()
    {
        return Media::query()
            ->when($this->mediaSearch, fn ($q, $s) => $q->where('name', 'like', "%{$s}%")->orWhere('alt', 'like', "%{$s}%"))
            ->latest('id')
            ->limit($this->mediaLimit)
            ->get();
    }

    #[Computed]
    public function words(): int
    {
        return str_word_count(strip_tags($this->body));
    }

    /** The slug is frozen once published: bookmarks and rankings depend on it. */
    #[Computed]
    public function slugLocked(): bool
    {
        return (bool) $this->post?->isLive();
    }

    public function updatedTitle(string $value): void
    {
        if (! $this->slugTouched && ! $this->slugLocked) {
            $this->slug = Str::slug($value);
        }
    }

    public function updatedSlug(): void
    {
        $this->slugTouched = true;
        $this->slug = Str::slug($this->slug);
    }

    // ---------------------------------------------------------------
    // Tags
    // ---------------------------------------------------------------

    public function addTag(): void
    {
        $name = trim($this->tagInput);

        if ($name === '') {
            return;
        }

        // Compare on the slug so "Field recording" and "field-recording"
        // never end up as two chips on the same post.
        $existing = collect($this->tags)->first(fn ($t) => Str::slug($t) === Str::slug($name));

        if (! $existing && count($this->tags) < 12) {
            $this->tags[] = $name;
        }

        $this->tagInput = '';
    }

    public function removeTag(string $name): void
    {
        $this->tags = array_values(array_filter($this->tags, fn ($t) => $t !== $name));
    }

    // ---------------------------------------------------------------
    // Media
    // ---------------------------------------------------------------

    public function openMedia(string $target = 'body'): void
    {
        $this->mediaTarget = $target;
        $this->showMedia = true;
        $this->mediaLimit = 12;
    }

    public function updatedUpload(): void
    {
        $this->validate(['upload' => ['required', 'image', 'max:8192']], [
            'upload.max' => 'Images over 8 MB are almost always a mistake — resize it first.',
        ]);

        try {
            $media = app(MediaLibrary::class)->store($this->upload);
        } catch (\RuntimeException $e) {
            $this->addError('upload', $e->getMessage());

            return;
        }

        $this->reset('upload');
        unset($this->library);

        $this->choose($media->id);
    }

    public function choose(int $id): void
    {
        $media = Media::findOrFail($id);

        if ($this->mediaTarget === 'cover') {
            $this->coverId = $media->id;
            unset($this->cover);
        } else {
            // The cursor lives in the browser, so the markdown is handed to
            // Alpine to drop in at the caret rather than appended blindly.
            $this->tab = 'write';
            $this->dispatch('insert-markdown', markdown: "\n\n".$media->markdown()."\n\n");
        }

        $this->showMedia = false;
    }

    public function saveAlt(int $id, string $alt): void
    {
        Media::whereKey($id)->update(['alt' => $alt ?: null]);
        unset($this->library);
    }

    public function deleteMedia(int $id): void
    {
        $media = Media::findOrFail($id);

        if ($this->coverId === $media->id) {
            $this->coverId = null;
            unset($this->cover);
        }

        $media->delete();
        unset($this->library);
    }

    // ---------------------------------------------------------------
    // Saving
    // ---------------------------------------------------------------

    protected function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:180'],
            'slug' => [
                'required', 'string', 'max:160', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('posts', 'slug')->where('type', $this->type)->ignore($this->postId),
            ],
            'excerpt' => ['nullable', 'string', 'max:300'],
            'body' => ['nullable', 'string'],
            'status' => ['required', 'in:draft,published'],
            'categoryId' => ['nullable', 'exists:post_categories,id'],
            'metaTitle' => ['nullable', 'string', 'max:255'],
            'metaDescription' => ['nullable', 'string', 'max:300'],
        ];
    }

    public function save(bool $redirect = true): void
    {
        $this->validate($this->rules(), [
            'slug.regex' => 'Lowercase letters, numbers and hyphens only.',
        ]);

        // A page sits at the root of the site, so its slug competes with
        // every real route. Checked against the routes that actually exist,
        // not a list somebody has to remember to update.
        if ($this->type === Post::TYPE_PAGE && ReservedSlugs::taken($this->slug)) {
            $this->addError('slug', "“{$this->slug}” is already a section of the site. Pick another.");

            return;
        }

        $publishedAt = $this->publishedAt ? Carbon::parse($this->publishedAt) : null;

        if ($this->status === Post::STATUS_PUBLISHED && ! $publishedAt) {
            $publishedAt = now();
            $this->publishedAt = $publishedAt->format('Y-m-d\TH:i');
        }

        $post = $this->postId ? Post::findOrFail($this->postId) : new Post;
        $isNew = ! $post->exists;

        $post->fill([
            'type' => $this->type,
            'user_id' => $post->user_id ?? auth()->id(),
            'post_category_id' => $this->type === Post::TYPE_POST ? $this->categoryId : null,
            'cover_media_id' => $this->coverId,
            'title' => $this->title,
            'slug' => $this->slug,
            'excerpt' => $this->excerpt ?: null,
            'body' => $this->body,
            'status' => $this->status,
            'published_at' => $publishedAt,
            'meta_title' => $this->metaTitle ?: null,
            'meta_description' => $this->metaDescription ?: null,
            'noindex' => $this->noindex,
            'reading_minutes' => Post::readingMinutes($this->body),
            'sort_order' => $this->sortOrder,
            // Forced false on a blog post rather than passed through. The
            // checkbox is only drawn for a page, so a post could only ever
            // carry a true here by accident — and an accident that writes a
            // column nothing reads is one somebody debugs twice.
            'in_footer' => $this->type === Post::TYPE_PAGE && $this->inFooter,
        ])->save();

        $post->tags()->sync(
            collect($this->tags)->map(fn ($name) => PostTag::fromName($name)->id)->all()
        );

        ActivityLog::record($isNew ? 'post.created' : 'post.updated', $post,
            ucfirst($this->type).": {$post->title}");

        $this->postId = $post->id;
        $this->savedAt = now()->format('H:i');

        // The two footer notes are computed from the database and memoised
        // for the request. The save that just happened is exactly what they
        // are describing, so they have to be dropped along with $this->post.
        unset($this->post, $this->footerNote, $this->footerCrowded);

        if ($isNew && $redirect) {
            $this->redirectRoute(
                $this->type === Post::TYPE_PAGE ? 'admin.pages.edit' : 'admin.blog.edit',
                $post,
                navigate: true,
            );

            return;
        }

        session()->flash('ok', 'Saved.');
    }

    /**
     * Called from the browser every 30 seconds. Losing an hour of writing to
     * a closed tab is the worst thing this screen could do, and a quiet save
     * costs nothing.
     */
    public function autosave(): void
    {
        if (! $this->postId || $this->title === '') {
            return;   // nothing to attach the draft to yet
        }

        Post::whereKey($this->postId)->update([
            'title' => $this->title,
            'body' => $this->body,
            'excerpt' => $this->excerpt ?: null,
            'reading_minutes' => Post::readingMinutes($this->body),
            'autosaved_at' => now(),
        ]);

        Cache::forget("post.{$this->postId}.html");

        $this->savedAt = now()->format('H:i');
    }

    public function publish(): void
    {
        $this->status = Post::STATUS_PUBLISHED;

        if (! $this->publishedAt) {
            $this->publishedAt = now()->format('Y-m-d\TH:i');
        }

        $this->save(redirect: false);
    }

    public function unpublish(): void
    {
        $this->status = Post::STATUS_DRAFT;
        $this->save(redirect: false);
    }
}; ?>

<div x-data="{
        pos: null,
        remember() {
            const el = this.$refs.editor;
            if (el) this.pos = [el.selectionStart, el.selectionEnd];
        },
        apply(before, after = '', placeholder = 'text') {
            const el = this.$refs.editor;
            if (! el) return;
            el.focus();
            const [s, e] = this.pos ?? [el.selectionStart, el.selectionEnd];
            const chosen = el.value.slice(s, e) || placeholder;
            el.setRangeText(before + chosen + after, s, e, 'end');
            el.dispatchEvent(new Event('input'));
            this.remember();
        },
        insert(text) {
            const el = this.$refs.editor;
            if (! el) return;
            const [s, e] = this.pos ?? [el.value.length, el.value.length];
            el.setRangeText(text, s, e, 'end');
            el.dispatchEvent(new Event('input'));
            this.remember();
        },
     }"
     @insert-markdown.window="$nextTick(() => insert($event.detail.markdown))"
     x-init="setInterval(() => { if ($wire.postId) $wire.autosave() }, 30000)">

    @if (session('ok'))
        <div class="mb-5 flex items-center gap-3 rounded-xl border border-success/30 bg-success/10 px-4 py-3">
            <x-icon name="circle-check" style="solid" class="text-success" />
            <span class="text-[0.88rem]">{{ session('ok') }}</span>
        </div>
    @endif

    <a href="{{ route($type === 'page' ? 'admin.pages' : 'admin.blog') }}" wire:navigate
       class="mb-4 inline-flex items-center gap-2 text-[0.8rem] text-paper/35 transition hover:text-paper">
        <x-icon name="arrow-left" style="solid" class="text-[11px]" />
        {{ $type === 'page' ? 'All pages' : 'All posts' }}
    </a>

    {{-- lg, not xl: the sidebar of the panel already eats 252px, so waiting
         for a 1280px viewport meant the two columns collapsed into one on a
         laptop — and a stacked layout puts Publish below a 28-row textarea. --}}
    <div class="grid gap-5 lg:grid-cols-[1fr_320px] xl:grid-cols-[1fr_360px]">

        {{-- ══════════════════════════════════════════════════════════
             COLUMN 1 — the editor, and underneath it, search engines
        ══════════════════════════════════════════════════════════ --}}
        <div class="min-w-0 space-y-5">

            <div class="rounded-2xl border border-hairline bg-panel">

                {{-- Title + address --}}
                <div class="border-b border-hairline p-5">
                    <input type="text" wire:model.live.debounce.400ms="title" placeholder="Title"
                           class="w-full border-0 bg-transparent p-0 text-[1.5rem] font-semibold tracking-[-0.02em] text-paper placeholder:text-paper/20 focus:outline-none focus:ring-0" />
                    @error('title') <p class="mt-1.5 text-[0.8rem] text-danger">{{ $message }}</p> @enderror

                    <div class="mt-3.5 flex flex-wrap items-center gap-2">
                        <span class="flex items-center gap-2 text-[0.78rem] text-paper/25">
                            <x-icon name="link" style="solid" class="text-[11px]" />
                            {{ $type === 'page' ? rtrim(url('/'), '/') : url('/blog') }}/
                        </span>

                        <input type="text" wire:model.live.debounce.400ms="slug" @disabled($this->slugLocked)
                               class="min-w-0 flex-1 rounded-lg border-0 bg-raised px-3 py-1.5 text-[0.78rem] text-paper focus:outline-none focus:ring-2 focus:ring-brand/40 disabled:opacity-45" />

                        @if ($this->slugLocked)
                            <span class="group/tip relative">
                                <x-icon name="lock" style="solid" class="text-[11px] text-paper/30" />
                                <span class="pointer-events-none absolute bottom-full right-0 z-50 mb-2 w-56 rounded-lg bg-paper px-3 py-2 text-[0.72rem] text-ink opacity-0 shadow-xl transition group-hover/tip:opacity-100">
                                    Frozen once published — bookmarks and search rankings point at it.
                                </span>
                            </span>
                        @endif

                        {{-- The finished address, opened in a new tab. Drafts
                             are visible to admins, so this works before the
                             post goes live. Shown from the start — greyed
                             until there is a slug — so the affordance is not
                             something you have to discover later. --}}
                        @if ($slug)
                            <a href="{{ $this->publicUrl }}" target="_blank" rel="noopener"
                               class="group/tip relative flex items-center gap-2 rounded-lg bg-raised px-3 py-1.5 text-[0.78rem] text-paper/60 transition duration-200 ease-dbelo hover:bg-paper/[0.10] hover:text-paper">
                                <x-icon name="arrow-up-right-from-square" style="solid" class="text-[10px]" />
                                Open
                                <span class="pointer-events-none absolute bottom-full right-0 z-50 mb-2 whitespace-nowrap rounded-lg bg-paper px-3 py-2 text-[0.72rem] text-ink opacity-0 shadow-xl transition group-hover/tip:opacity-100">
                                    {{ $this->publicUrl }}
                                </span>
                            </a>
                        @else
                            <span class="group/tip relative flex cursor-not-allowed items-center gap-2 rounded-lg bg-raised px-3 py-1.5 text-[0.78rem] text-paper/25">
                                <x-icon name="arrow-up-right-from-square" style="solid" class="text-[10px]" />
                                Open
                                <span class="pointer-events-none absolute bottom-full right-0 z-50 mb-2 whitespace-nowrap rounded-lg bg-paper px-3 py-2 text-[0.72rem] text-ink opacity-0 shadow-xl transition group-hover/tip:opacity-100">
                                    Give it a title first — the address comes from it
                                </span>
                            </span>
                        @endif
                    </div>
                    @error('slug') <p class="mt-1.5 text-[0.8rem] text-danger">{{ $message }}</p> @enderror
                </div>

                {{-- Write / Preview --}}
                <div class="flex items-center gap-1.5 border-b border-hairline px-3 py-2">
                    @foreach (['write' => ['Write', 'pen'], 'preview' => ['Preview', 'eye']] as $key => [$label, $icon])
                        <button wire:click="$set('tab', '{{ $key }}')"
                                @class([
                                    'flex items-center gap-2 rounded-lg px-3.5 py-2 text-[0.82rem] transition duration-200 ease-dbelo',
                                    'bg-raised text-paper' => $tab === $key,
                                    'text-paper/45 hover:text-paper' => $tab !== $key,
                                ])>
                            <x-icon :name="$icon" style="solid" class="text-[11px]" />
                            {{ $label }}
                        </button>
                    @endforeach

                    <span class="ml-auto flex items-center gap-3 pr-2 text-[0.72rem] text-paper/25">
                        @if ($savedAt)
                            <span class="flex items-center gap-1.5">
                                <x-icon name="cloud-check" style="solid" class="text-[10px] text-success/70" />
                                {{ $savedAt }}
                            </span>
                        @endif
                        <span>{{ number_format($this->words) }} words · {{ \App\Models\Post::readingMinutes($body) }} min</span>
                    </span>
                </div>

                @if ($tab === 'write')
                    {{-- Toolbar: it writes the markdown so you never have to
                         remember the syntax. --}}
                    <div class="flex flex-wrap items-center gap-1 border-b border-hairline px-3 py-2">
                        @foreach ([
                            ['bold', 'Bold', '**', '**', 'bold text'],
                            ['italic', 'Italic', '*', '*', 'italic text'],
                            ['heading', 'Heading', '## ', '', 'Heading'],
                            ['h3', 'Subheading', '### ', '', 'Subheading'],
                            ['link', 'Link', '[', '](https://)', 'link text'],
                            ['list-ul', 'Bullet list', '- ', '', 'item'],
                            ['list-ol', 'Numbered list', '1. ', '', 'item'],
                            ['quote-left', 'Quote', '> ', '', 'quote'],
                            ['code', 'Code', '`', '`', 'code'],
                            ['table-cells', 'Table', "\n| Column | Column |\n| --- | --- |\n| Cell | Cell |\n", '', ''],
                            ['minus', 'Divider', "\n---\n", '', ''],
                        ] as [$icon, $label, $before, $after, $placeholder])
                            <span class="group/tip relative">
                                <button type="button"
                                        @click="apply(@js($before), @js($after), @js($placeholder))"
                                        class="grid size-8 place-items-center rounded-full text-paper/45 transition duration-200 ease-dbelo hover:bg-paper/[0.10] hover:text-paper">
                                    <x-icon :name="$icon" style="solid" class="text-[11px]" />
                                </button>
                                <span class="pointer-events-none absolute bottom-full left-1/2 z-50 mb-2 -translate-x-1/2 whitespace-nowrap rounded-lg bg-paper px-2.5 py-1 text-[0.7rem] font-medium text-ink opacity-0 shadow-xl transition group-hover/tip:opacity-100">{{ $label }}</span>
                            </span>
                        @endforeach

                        <span class="mx-1 h-5 w-px bg-hairline"></span>

                        <x-admin.icon-button icon="image" label="Insert image" wire:click="openMedia('body')" />
                    </div>

                    <div class="p-5">
                        <textarea x-ref="editor"
                                  wire:model.live.debounce.900ms="body"
                                  @keyup="remember()" @mouseup="remember()" @blur="remember()"
                                  rows="28" spellcheck="true"
                                  placeholder="Write in markdown. The toolbar inserts it for you, so you never have to remember the syntax."
                                  class="w-full resize-y border-0 bg-transparent p-0 font-mono text-[0.88rem] leading-[1.75] text-paper placeholder:text-paper/20 focus:outline-none focus:ring-0"></textarea>
                    </div>
                @else
                    <div class="min-h-[38rem] bg-canvas/40 p-8">
                        @if (trim($body) === '')
                            <div class="py-24 text-center">
                                <x-icon name="file-lines" style="regular" class="text-[24px] text-paper/20" />
                                <p class="mt-3 text-[0.9rem] text-paper/35">Nothing written yet</p>
                            </div>
                        @else
                            <x-prose class="!text-paper/75 [&_h2]:!text-paper [&_h3]:!text-paper [&_h4]:!text-paper [&_strong]:!text-paper [&_code]:!bg-paper/10 [&_hr]:!border-paper/10 [&_th]:!border-paper/10 [&_td]:!border-paper/5">
                                {!! $this->preview !!}
                            </x-prose>
                        @endif
                    </div>
                @endif
            </div>

            {{-- ══════ SEARCH ENGINES ══════ --}}
            <div class="rounded-2xl border border-hairline bg-panel">
                <div class="flex items-center gap-3 border-b border-hairline px-5 py-4">
                    <x-admin.icon-chip icon="magnifying-glass" tone="brand" />
                    <h2 class="text-[0.95rem] font-medium">Search engines</h2>
                </div>

                <div class="grid gap-5 p-5 lg:grid-cols-2">
                    <label class="block">
                        <span class="mb-2 block text-[0.72rem] uppercase tracking-[0.14em] text-paper/35">Title in Google</span>
                        <input type="text" wire:model.live.debounce.500ms="metaTitle" placeholder="{{ $title ?: 'Uses the title above' }}"
                               class="w-full rounded-lg border-0 bg-raised px-3.5 py-2.5 text-[0.88rem] text-paper placeholder:text-paper/25 focus:outline-none focus:ring-2 focus:ring-brand/40" />
                        @error('metaTitle') <p class="mt-1.5 text-[0.78rem] text-danger">{{ $message }}</p> @enderror
                    </label>

                    <label class="block">
                        <span class="mb-2 block text-[0.72rem] uppercase tracking-[0.14em] text-paper/35">Excerpt</span>
                        <input type="text" wire:model="excerpt"
                               placeholder="Shown in the listing. Empty takes the first lines."
                               class="w-full rounded-lg border-0 bg-raised px-3.5 py-2.5 text-[0.88rem] text-paper placeholder:text-paper/25 focus:outline-none focus:ring-2 focus:ring-brand/40" />
                        @error('excerpt') <p class="mt-1.5 text-[0.78rem] text-danger">{{ $message }}</p> @enderror
                    </label>

                    <label class="block lg:col-span-2">
                        <span class="mb-2 flex items-center justify-between text-[0.72rem] uppercase tracking-[0.14em] text-paper/35">
                            Description
                            <span @class([
                                'tabular-nums',
                                'text-warning' => strlen($metaDescription) > 160,
                                'text-paper/25' => strlen($metaDescription) <= 160,
                            ])>{{ strlen($metaDescription) }}/160</span>
                        </span>
                        <textarea wire:model.live.debounce.500ms="metaDescription" rows="2"
                                  placeholder="What Google shows under the title. Left empty, it uses the excerpt."
                                  class="w-full resize-none rounded-lg border-0 bg-raised px-3.5 py-2.5 text-[0.88rem] text-paper placeholder:text-paper/25 focus:outline-none focus:ring-2 focus:ring-brand/40"></textarea>
                        @error('metaDescription') <p class="mt-1.5 text-[0.78rem] text-danger">{{ $message }}</p> @enderror
                    </label>

                    {{-- How the result will actually look. Cheaper than
                         guessing whether the description got cut off. --}}
                    <div class="rounded-xl bg-raised p-4 lg:col-span-2">
                        <div class="mb-2 text-[0.7rem] uppercase tracking-[0.14em] text-paper/30">Result preview</div>
                        <div class="truncate text-[0.75rem] text-success/70">{{ $this->publicUrl }}</div>
                        <div class="mt-1 truncate text-[1rem] text-info">{{ $metaTitle ?: ($title ?: 'Untitled') }}</div>
                        <p class="mt-1 line-clamp-2 text-[0.82rem] leading-relaxed text-paper/45">
                            {{ $metaDescription ?: ($excerpt ?: Str::limit(trim(strip_tags($this->preview)), 160)) }}
                        </p>
                    </div>

                    <label class="flex cursor-pointer items-start gap-2.5 lg:col-span-2">
                        <input type="checkbox" wire:model="noindex"
                               class="mt-0.5 size-4 shrink-0 rounded border-hairline bg-raised text-brand focus:ring-brand" />
                        <span class="text-[0.82rem] leading-relaxed text-paper/60">
                            Hide from Google
                            <span class="block text-[0.75rem] text-paper/30">For thank-you pages and anything you do not want indexed.</span>
                        </span>
                    </label>
                </div>
            </div>
        </div>

        {{-- ══════════════════════════════════════════════════════════
             COLUMN 2 — actions, status, category, tags, cover
        ══════════════════════════════════════════════════════════ --}}
        {{-- Sticky: on a long post the Publish button must never be a
             scroll away from whatever you are writing. --}}
        <div class="h-fit space-y-5 lg:sticky lg:top-6">

            {{-- Actions --}}
            <div class="rounded-2xl border border-hairline bg-panel p-5">
                <div class="flex gap-2">
                    <button wire:click="save"
                            class="flex flex-1 items-center justify-center gap-2 rounded-lg bg-raised px-4 py-2.5 text-[0.85rem] text-paper/70 transition duration-200 ease-dbelo hover:bg-paper/[0.10] hover:text-paper">
                        <x-icon name="floppy-disk" style="solid" class="text-[11px]" />
                        <span wire:loading.remove wire:target="save">Save draft</span>
                        <span wire:loading wire:target="save">Saving…</span>
                    </button>

                    @if ($status === 'published')
                        <button wire:click="unpublish"
                                class="group/tip relative grid size-[42px] shrink-0 place-items-center rounded-lg bg-warning/15 text-warning transition duration-200 ease-dbelo hover:bg-warning/25">
                            <x-icon name="eye-slash" style="solid" class="text-[12px]" />
                            <span class="pointer-events-none absolute bottom-full right-0 z-50 mb-2 whitespace-nowrap rounded-lg bg-paper px-2.5 py-1 text-[0.7rem] font-medium text-ink opacity-0 shadow-xl transition group-hover/tip:opacity-100">Unpublish</span>
                        </button>
                    @endif
                </div>

                @if ($status !== 'published')
                    <button wire:click="publish"
                            class="mt-2 flex w-full items-center justify-center gap-2 rounded-lg bg-action px-4 py-3 text-[0.88rem] font-medium text-white transition duration-200 ease-dbelo hover:brightness-110">
                        <x-icon name="paper-plane" style="solid" class="text-[12px]" />
                        Publish
                    </button>
                @else
                    <button wire:click="save"
                            class="mt-2 flex w-full items-center justify-center gap-2 rounded-lg bg-action px-4 py-3 text-[0.88rem] font-medium text-white transition duration-200 ease-dbelo hover:brightness-110">
                        <x-icon name="arrows-rotate" style="solid" class="text-[12px]" />
                        Update
                    </button>
                @endif
            </div>

            {{-- Status --}}
            <div class="rounded-2xl border border-hairline bg-panel">
                <div class="flex items-center gap-3 border-b border-hairline px-5 py-4">
                    <x-admin.icon-chip :icon="$this->post?->isLive() ? 'circle-check' : ($this->post?->isScheduled() ? 'clock' : 'pen-ruler')"
                                       :tone="$this->post?->isLive() ? 'brand' : 'muted'" />
                    <h2 class="text-[0.95rem] font-medium">Status</h2>
                    <span @class([
                        'ml-auto rounded-full px-2.5 py-1 text-[0.72rem]',
                        'bg-success/15 text-success' => $this->post?->isLive(),
                        'bg-info/15 text-info' => $this->post?->isScheduled(),
                        'bg-raised text-paper/45' => ! $this->post?->isLive() && ! $this->post?->isScheduled(),
                    ])>
                        {{ $this->post?->isLive() ? 'Live' : ($this->post?->isScheduled() ? 'Scheduled' : 'Draft') }}
                    </span>
                </div>

                <div class="p-5">
                    <label class="block">
                        <span class="mb-2 block text-[0.72rem] uppercase tracking-[0.14em] text-paper/35">Publish date</span>
                        <input type="datetime-local" wire:model="publishedAt"
                               class="w-full rounded-lg border-0 bg-raised px-3.5 py-2.5 text-[0.85rem] text-paper focus:outline-none focus:ring-2 focus:ring-brand/40" />
                    </label>
                    <p class="mt-2 text-[0.73rem] leading-relaxed text-paper/30">
                        A date in the future schedules it. It goes live on its own — there is no job to keep running.
                    </p>

                    {{-- ── THE FOOTER, FOR PAGES ────────────────────────
                         The order box used to be here on its own, which made
                         it look like the decision — it was not. Every
                         published page went into the footer and this only
                         said where. The checkbox is the decision; the number
                         only matters once it is ticked, which is why it is
                         not drawn until then. --}}
                    @if ($type === 'page')
                        <div class="mt-4 border-t border-hairline pt-4">
                            <label class="flex cursor-pointer items-start gap-3">
                                <input type="checkbox" wire:model.live="inFooter"
                                       class="mt-0.5 size-4 shrink-0 rounded border-0 bg-raised text-brand focus:ring-2 focus:ring-brand/40" />
                                <span>
                                    <span class="block text-[0.85rem]">Show in the footer</span>
                                    <span class="mt-1 block text-[0.73rem] leading-relaxed text-paper/30">
                                        Under <span class="text-paper/50">Resources</span>, on every page of the site.
                                        Off unless you say so — a page written for one link in one email does not
                                        belong down there.
                                    </span>
                                </span>
                            </label>

                            @if ($inFooter)
                                <label class="mt-4 block">
                                    <span class="mb-2 block text-[0.72rem] uppercase tracking-[0.14em] text-paper/35">Order in the footer</span>
                                    <input type="number" wire:model.live="sortOrder" min="0" max="99"
                                           class="w-full rounded-lg border-0 bg-raised px-3.5 py-2.5 text-[0.85rem] text-paper focus:outline-none focus:ring-2 focus:ring-brand/40" />
                                    <span class="mt-2 block text-[0.73rem] leading-relaxed text-paper/30">
                                        Lowest number first. The column holds {{ \App\Models\Post::FOOTER_MAX }} pages at most —
                                        anything past that is still live and still in the sitemap, just not in the footer.
                                    </span>
                                </label>

                                {{-- Says so out loud when the box is ticked
                                     and the page is not actually being
                                     drawn. A ticked checkbox that does
                                     nothing is the exact failure this whole
                                     change was meant to remove. --}}
                                @if ($this->footerNote)
                                    <p @class([
                                        'mt-3 rounded-lg px-3 py-2.5 text-[0.75rem] leading-relaxed',
                                        'bg-warning/[0.08] text-warning' => $this->footerCrowded,
                                        'bg-paper/[0.04] text-paper/40' => ! $this->footerCrowded,
                                    ])>{{ $this->footerNote }}</p>
                                @endif
                            @endif
                        </div>
                    @endif
                </div>
            </div>

            {{-- Category + tags (blog only) --}}
            @if ($type === 'post')
                <div class="rounded-2xl border border-hairline bg-panel">
                    <div class="flex items-center gap-3 border-b border-hairline px-5 py-4">
                        <x-admin.icon-chip icon="folder-tree" tone="brand" />
                        <h2 class="text-[0.95rem] font-medium">Category</h2>
                    </div>

                    <div class="p-5">
                        <select wire:model="categoryId"
                                class="w-full rounded-lg border-0 bg-raised px-3.5 py-2.5 text-[0.85rem] text-paper focus:outline-none focus:ring-2 focus:ring-brand/40">
                            <option value="">Uncategorised</option>
                            @foreach ($this->categories as $category)
                                <option value="{{ $category->id }}">{{ $category->name }}</option>
                            @endforeach
                        </select>

                        <a href="{{ route('admin.post-categories') }}" wire:navigate
                           class="mt-2.5 flex items-center gap-2 text-[0.75rem] text-paper/30 transition hover:text-paper">
                            <x-icon name="gear" style="solid" class="text-[10px]" />
                            Manage categories
                        </a>
                    </div>
                </div>

                <div class="rounded-2xl border border-hairline bg-panel">
                    <div class="flex items-center gap-3 border-b border-hairline px-5 py-4">
                        <x-admin.icon-chip icon="tags" tone="brand" />
                        <h2 class="text-[0.95rem] font-medium">Tags</h2>
                        <span class="ml-auto text-[0.75rem] text-paper/25">{{ count($tags) }}/12</span>
                    </div>

                    <div class="p-5">
                        @if ($tags)
                            <div class="mb-3 flex flex-wrap gap-1.5">
                                @foreach ($tags as $tag)
                                    <button wire:key="tag-{{ $loop->index }}" wire:click="removeTag(@js($tag))"
                                            class="group/t flex items-center gap-1.5 rounded-full bg-raised px-3 py-1.5 text-[0.78rem] text-paper/70 transition duration-200 ease-dbelo hover:bg-danger/15 hover:text-danger">
                                        {{ $tag }}
                                        <x-icon name="xmark" style="solid" class="text-[9px] opacity-40 group-hover/t:opacity-100" />
                                    </button>
                                @endforeach
                            </div>
                        @endif

                        @if (count($tags) < 12)
                            <input type="text" wire:model="tagInput" wire:keydown.enter.prevent="addTag" wire:blur="addTag"
                                   placeholder="Type a tag and press Enter"
                                   class="w-full rounded-lg border-0 bg-raised px-3.5 py-2.5 text-[0.85rem] text-paper placeholder:text-paper/25 focus:outline-none focus:ring-2 focus:ring-brand/40" />
                        @endif

                        <a href="{{ route('admin.post-tags') }}" wire:navigate
                           class="mt-2.5 flex items-center gap-2 text-[0.75rem] text-paper/30 transition hover:text-paper">
                            <x-icon name="gear" style="solid" class="text-[10px]" />
                            Manage tags
                        </a>
                    </div>
                </div>
            @endif

            {{-- Cover --}}
            <div class="rounded-2xl border border-hairline bg-panel">
                <div class="flex items-center gap-3 border-b border-hairline px-5 py-4">
                    <x-admin.icon-chip icon="image" tone="brand" />
                    <h2 class="text-[0.95rem] font-medium">Cover image</h2>
                </div>

                <div class="p-5">
                    @if ($this->cover)
                        <img src="{{ $this->cover->url() }}" alt="{{ $this->cover->alt }}"
                             class="aspect-[16/9] w-full rounded-xl object-cover" />

                        <div class="mt-2.5 flex gap-2">
                            <button wire:click="openMedia('cover')"
                                    class="flex flex-1 items-center justify-center gap-2 rounded-lg bg-raised px-3 py-2 text-[0.8rem] text-paper/70 transition hover:text-paper">
                                <x-icon name="arrows-rotate" style="solid" class="text-[10px]" />
                                Replace
                            </button>
                            <x-admin.icon-button icon="trash" label="Remove cover"
                                                 class="hover:!bg-danger/15 hover:!text-danger"
                                                 wire:click="$set('coverId', null)" />
                        </div>

                        @unless ($this->cover->alt)
                            <p class="mt-2.5 flex items-start gap-2 text-[0.73rem] leading-relaxed text-warning/80">
                                <x-icon name="triangle-exclamation" style="solid" class="mt-0.5 text-[10px]" />
                                No alt text. Add it in the library — it is what a screen reader says and what Google reads.
                            </p>
                        @endunless
                    @else
                        <button wire:click="openMedia('cover')"
                                class="grid aspect-[16/9] w-full place-items-center rounded-xl border border-dashed border-hairline text-paper/30 transition duration-200 ease-dbelo hover:border-paper/25 hover:text-paper/55">
                            <span class="text-center">
                                <x-icon name="image" style="regular" class="text-[18px]" />
                                <span class="mt-2 block text-[0.8rem]">Choose a cover</span>
                            </span>
                        </button>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- ══════ MEDIA LIBRARY ══════ --}}
    @if ($showMedia)
        <div class="fixed inset-0 z-[100] flex items-start justify-center overflow-y-auto bg-rail/80 p-6 backdrop-blur-sm"
             wire:click.self="$set('showMedia', false)">

            <div class="mt-10 w-full max-w-4xl rounded-2xl border border-hairline bg-panel shadow-float">

                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-hairline px-5 py-4">
                    <h2 class="flex items-center gap-3 text-[0.95rem] font-medium">
                        <x-admin.icon-chip :icon="$mediaTarget === 'cover' ? 'image' : 'file-image'" tone="brand" />
                        {{ $mediaTarget === 'cover' ? 'Choose a cover' : 'Insert an image' }}
                    </h2>

                    <div class="flex items-center gap-2">
                        <div class="flex items-center gap-2.5 rounded-lg bg-raised px-3 py-2">
                            <x-icon name="magnifying-glass" style="regular" class="text-[12px] text-paper/35" />
                            <input type="search" wire:model.live.debounce.300ms="mediaSearch" placeholder="Search…"
                                   class="w-36 border-0 bg-transparent p-0 text-[0.83rem] text-paper placeholder:text-paper/30 focus:outline-none focus:ring-0" />
                        </div>

                        <label class="flex cursor-pointer items-center gap-2 rounded-lg bg-action px-4 py-2.5 text-[0.83rem] font-medium text-white transition hover:brightness-110">
                            <x-icon name="arrow-up-from-bracket" style="solid" class="text-[11px]" />
                            <span wire:loading.remove wire:target="upload">Upload</span>
                            <span wire:loading wire:target="upload">Uploading…</span>
                            <input type="file" wire:model="upload" accept="image/*" class="hidden" />
                        </label>

                        <x-admin.icon-button icon="xmark" variant="muted" label="Close" wire:click="$set('showMedia', false)" />
                    </div>
                </div>

                @error('upload')
                    <p class="flex items-center gap-2 border-b border-hairline px-5 py-3 text-[0.82rem] text-danger">
                        <x-icon name="triangle-exclamation" style="solid" class="text-[11px]" />
                        {{ $message }}
                    </p>
                @enderror

                <div class="max-h-[60vh] overflow-y-auto p-5">
                    @if ($this->library->isEmpty())
                        <div class="py-16 text-center">
                            <x-icon name="images" style="regular" class="text-[24px] text-paper/20" />
                            <p class="mt-3 text-[0.9rem] text-paper/45">Nothing here yet</p>
                            <p class="mt-1 text-[0.8rem] text-paper/25">Everything you upload is converted to WebP and kept for reuse.</p>
                        </div>
                    @else
                        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                            @foreach ($this->library as $item)
                                <div wire:key="m-{{ $item->id }}" class="group/m overflow-hidden rounded-xl bg-raised">
                                    <button wire:click="choose({{ $item->id }})" class="block w-full">
                                        <img src="{{ $item->url() }}" alt="{{ $item->alt }}" loading="lazy"
                                             class="aspect-[4/3] w-full object-cover transition duration-300 ease-dbelo group-hover/m:brightness-110" />
                                    </button>

                                    <div class="p-2.5">
                                        <input type="text" value="{{ $item->alt }}"
                                               wire:change="saveAlt({{ $item->id }}, $event.target.value)"
                                               placeholder="Alt text…"
                                               class="w-full border-0 bg-transparent p-0 text-[0.72rem] text-paper placeholder:text-warning/60 focus:outline-none focus:ring-0" />

                                        <div class="mt-1.5 flex items-center justify-between text-[0.68rem] text-paper/25">
                                            <span>{{ $item->width }}×{{ $item->height }} · {{ $item->sizeForHumans() }}</span>
                                            <button wire:click="deleteMedia({{ $item->id }})"
                                                    wire:confirm="Delete this image? Any post already using it will show a broken image."
                                                    class="transition hover:text-danger">
                                                <x-icon name="trash" style="solid" class="text-[10px]" />
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        @if ($this->library->count() >= $mediaLimit)
                            <div class="mt-5 text-center">
                                <button wire:click="$set('mediaLimit', {{ $mediaLimit + 12 }})"
                                        class="rounded-lg bg-raised px-5 py-2.5 text-[0.83rem] text-paper/55 transition hover:text-paper">
                                    Show more
                                </button>
                            </div>
                        @endif
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
