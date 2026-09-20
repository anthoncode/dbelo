<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use App\Services\RedirectResolver;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * A page or a blog post. `type` tells them apart.
 *
 * The body is markdown and stays markdown: it is portable, it survives any
 * editor we swap in later, and it cannot carry broken HTML into the page.
 */
class Post extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    public const TYPE_POST = 'post';

    public const TYPE_PAGE = 'page';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'autosaved_at' => 'datetime',
            'noindex' => 'boolean',
            'in_footer' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // The rendered HTML is cached per post; any save invalidates it, plus
        // the two lists that show pages and posts on every page view.
        $flush = function (Post $post) {
            Cache::forget("post.{$post->id}.html");
            Cache::forget(self::FOOTER_CACHE);
            Cache::forget('blog.has_posts');
            Cache::forget('blog.feed');
            Cache::forget('sitemap.xml');
        };

        static::saved($flush);
        static::deleted($flush);

        /*
         * A slug that changes leaves a redirect behind.
         *
         * ── WHY THIS IS NOT OPTIONAL ─────────────────────────────────────
         *
         * Renaming a published post used to break every link that already
         * pointed at it — links inside other posts, whatever was shared on
         * social, and whatever Google had indexed — with no error anywhere.
         * The old URL simply started answering 404, and nothing in the panel
         * said so until somebody happened to open Admin → SEO and read the
         * broken-links list.
         *
         * The redirects table was built for exactly this: `source` has had a
         * 'post' => 'Page or post moved' entry since the day it was written.
         * It was just never wired up.
         *
         * ── THE THREE CARE POINTS ────────────────────────────────────────
         *
         *  1. Only for posts that WERE published. Renaming a draft changes a
         *     URL nobody could reach, and writing a rule for it fills the
         *     table with noise that makes the real rules harder to read.
         *
         *  2. collapse() before writing. If /a already redirects to /b and
         *     the slug now moves to /c, storing "/a → /b, /b → /c" is two
         *     round trips for the visitor and becomes an infinite loop the
         *     day somebody writes /c → /a. Collapsing means the table can
         *     never hold a chain.
         *
         *  3. repointTo() after writing. The rule written last month that
         *     points at the OLD url gets repaired to point past it, instead
         *     of quietly turning into the hop that (2) just avoided.
         *
         * updated() rather than updating(): the redirect should only exist
         * if the rename actually committed. At this point getOriginal() still
         * holds the pre-save values — syncOriginal() runs after this fires.
         */
        static::updated(function (Post $post) {
            if (! $post->wasChanged('slug') || ! Schema::hasTable('redirects')) {
                return;
            }

            // Was this URL ever reachable? See care point 1.
            if ($post->getOriginal('status') !== self::STATUS_PUBLISHED) {
                return;
            }

            $old = (string) $post->getOriginal('slug');
            $new = (string) $post->slug;

            if ($old === '' || $new === '' || $old === $new) {
                return;
            }

            // Pages live at the root, posts under /blog/. Post::url() is the
            // other half of this and the two must not disagree.
            $prefix = $post->isPage() ? '' : 'blog/';

            $from = RedirectResolver::normalise($prefix.$old);
            $to = '/'.$prefix.$new;

            Redirect::updateOrCreate(
                ['from' => $from],
                [
                    'to' => Redirect::collapse($to),
                    'status' => 301,
                    'is_wildcard' => false,
                    'source' => 'post',
                    'note' => "Slug changed: {$old} → {$new}",
                ],
            );

            Redirect::repointTo($from, $to);
        });
    }

    // ---------------------------------------------------------------
    // Relationships
    // ---------------------------------------------------------------

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(PostCategory::class, 'post_category_id');
    }

    public function cover(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'cover_media_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(PostTag::class, 'post_post_tag');
    }

    // ---------------------------------------------------------------
    // Scopes
    // ---------------------------------------------------------------

    /**
     * Live right now. The published_at check is what makes scheduling work
     * without a scheduler: a post dated tomorrow simply does not match.
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    public function scopePosts(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_POST);
    }

    public function scopePages(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_PAGE);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function isPage(): bool
    {
        return $this->type === self::TYPE_PAGE;
    }

    public function isLive(): bool
    {
        return $this->status === self::STATUS_PUBLISHED
            && $this->published_at !== null
            && $this->published_at->isPast();
    }

    /** Published, but dated in the future: written and waiting. */
    public function isScheduled(): bool
    {
        return $this->status === self::STATUS_PUBLISHED
            && $this->published_at?->isFuture();
    }

    public function url(): string
    {
        return $this->isPage()
            ? route('pages.show', $this->slug)
            : route('blog.show', $this->slug);
    }

    /**
     * Markdown → HTML, cached.
     *
     * The same call renders the preview in the editor, so what you see while
     * writing is what the page will be — not a second markdown flavour that
     * quietly disagrees.
     */
    public function html(): string
    {
        return Cache::rememberForever(
            "post.{$this->id}.html",
            fn () => static::render($this->body ?? '')
        );
    }

    public static function render(string $markdown): string
    {
        return Str::markdown($markdown, [
            'html_input' => 'allow',      // only admins write here
            'allow_unsafe_links' => false,
        ]);
    }

    public function summary(int $length = 160): string
    {
        return $this->excerpt
            ?: Str::limit(trim(strip_tags(static::render($this->body ?? ''))), $length);
    }

    /** 200 words a minute, floor of one. Nobody wants "0 min read". */
    public static function readingMinutes(?string $body): int
    {
        return max(1, (int) ceil(str_word_count(strip_tags((string) $body)) / 200));
    }

    // ---------------------------------------------------------------
    // Site chrome
    // ---------------------------------------------------------------
    //
    // Both of these render on every single page view, so both are cached and
    // both live here rather than in the layout. Keeping them out of the
    // Blade file is not only tidiness: a @php…@endphp block in a template
    // that also uses the inline @php(...) form makes Blade swallow
    // everything between the two, which is a very confusing way to break a
    // site.

    /**
     * How many pages the footer will draw.
     *
     * ── WHY THERE IS A CEILING AT ALL ────────────────────────────────────
     *
     * The column is a column. Six links under a heading is a list somebody
     * reads; fourteen is a wall, and the fourteenth link gets less attention
     * than it would have got from not being there — it makes the other
     * thirteen harder to see as well.
     *
     * It is not a limit on how many pages the site can have. A page that
     * does not fit here is still live, still in the sitemap, still linked
     * from wherever it was written to be linked from. The footer is a
     * shortcut, not a table of contents.
     */
    public const FOOTER_MAX = 6;

    /**
     * Bumped from `footer.pages` when in_footer arrived.
     *
     * The cached payload changed shape and meaning on the same day: it gained
     * an id, and it stopped meaning "every published page". A key that stays
     * the same across that would serve the OLD list — unfiltered, without
     * ids — for up to an hour after the migration, which is exactly the hour
     * somebody would spend wondering why the checkbox does nothing.
     */
    private const FOOTER_CACHE = 'footer.pages.v2';

    /**
     * The pages listed in the footer, as plain arrays — what goes into the
     * cache is exactly what comes out.
     *
     * The id is in there for the admin screens, which mark the rows that
     * actually made it in. One query decides it, and both the footer and the
     * panel read that one answer — otherwise the panel would be guessing at
     * the footer's rule, and would eventually guess wrong.
     *
     * @return array<int, array{id: int, slug: string, title: string}>
     */
    public static function footerPages(): array
    {
        return Cache::remember(self::FOOTER_CACHE, now()->addHour(), fn () => static::pages()
            ->live()
            ->where('in_footer', true)
            ->orderBy('sort_order')
            ->orderBy('title')
            ->limit(self::FOOTER_MAX)
            ->get(['id', 'slug', 'title'])
            ->map(fn (self $page) => ['id' => $page->id, 'slug' => $page->slug, 'title' => $page->title])
            ->all());
    }

    /**
     * How many live pages are ASKING to be in the footer.
     *
     * Not the same number as count(footerPages()) once there are more than
     * six, and the difference is the whole point: the admin screens use the
     * gap to say "two of these are not being shown" instead of letting a
     * ticked checkbox quietly do nothing.
     *
     * Uncached on purpose — it is read on two admin screens, never on a
     * public page, and a stale answer here would be a lie told to the one
     * person who can act on it.
     */
    public static function footerPagesWanted(): int
    {
        return static::pages()->live()->where('in_footer', true)->count();
    }

    /**
     * Ids of the pages the footer is actually drawing.
     *
     * @return array<int, int>
     */
    public static function footerPageIds(): array
    {
        return array_column(static::footerPages(), 'id');
    }

    /** Whether the Blog link belongs in the nav at all. */
    public static function blogHasPosts(): bool
    {
        return Cache::remember('blog.has_posts', now()->addHour(),
            fn () => static::posts()->live()->exists());
    }

    public static function uniqueSlug(string $title, string $type, ?int $ignore = null): string
    {
        $base = Str::slug($title) ?: 'untitled';
        $slug = $base;
        $n = 2;

        while (static::withTrashed()
            ->where('type', $type)
            ->where('slug', $slug)
            ->when($ignore, fn ($q) => $q->whereKeyNot($ignore))
            ->exists()
        ) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }
}
