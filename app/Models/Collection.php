<?php

namespace App\Models;

use App\Support\CleanWords;
use App\Support\FooterLinks;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Collection extends Model
{
    protected $guarded = [];

    /* ═══════════════════════ The three states ═══════════════════════ */

    /** Nobody but the owner. The link 404s for everyone else. */
    public const PRIVATE = 'private';

    /** Anybody with the link. Not in the directory, never indexed. */
    public const UNLISTED = 'unlisted';

    /** In the directory. Indexed only if the admin allows it. */
    public const LISTED = 'listed';

    /**
     * The header and the footer both ask "are there any packs? any listed
     * collections?", and both read a cached answer. This is what keeps that
     * answer honest: publish a pack and the link is there on the next page,
     * not within the hour.
     *
     * Same hook Post already uses for footer.pages. It fires more often here
     * — sounds_count is written every time somebody saves a sound into a
     * collection — but forgetting three cache keys costs nothing next to the
     * write that just happened.
     */
    protected static function booted(): void
    {
        static::saved(fn () => FooterLinks::flush());
        static::deleted(fn () => FooterLinks::flush());
    }

    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
            'is_listed' => 'boolean',
            'is_featured' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sounds(): BelongsToMany
    {
        // A curated pack has an intended sequence, so sort_order leads and
        // the timestamp only breaks ties.
        return $this->belongsToMany(Sound::class)
            ->withPivot(['sort_order', 'created_at'])
            ->orderByPivot('sort_order')
            ->orderByPivot('created_at', 'desc');
    }

    /**
     * Packs are the collections dbelo curates itself: public, and flagged
     * as featured so they can be shown as official.
     */
    public function scopePacks($query)
    {
        return $query->where('is_featured', true);
    }

    public function scopePublic($query)
    {
        return $query->where('is_public', true);
    }

    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true)->where('is_public', true);
    }

    /**
     * Everything that is somebody's own collection rather than one of ours.
     *
     * ── WHY THIS EXISTS AS A SCOPE ───────────────────────────────────────
     *
     * Packs live in this table and are made the same way — the admin screen
     * calls auth()->user()->collections()->create(['is_featured' => true]).
     * So every query that means "this person's collections" and forgets the
     * flag also returns the packs the admin built, and the packs then turn
     * up wearing collection controls: a visibility menu, a Delete button, a
     * row in a library.
     *
     * scopePacks() is the other half of the same pair. Anything that means
     * one of the two should say so, rather than leaving it to whoever writes
     * the next query to remember.
     */
    public function scopeNotPack($query)
    {
        return $query->where('is_featured', false);
    }

    public function isPack(): bool
    {
        return (bool) $this->is_featured;
    }

    /**
     * What the public collections directory shows.
     *
     * Three conditions, each of them load-bearing:
     *
     *   is_listed AND is_public — the owner opted in. Sharing a link is not
     *   opting in, which is the entire reason is_listed exists.
     *
     *   notPack — packs are collections too, and they already have /packs.
     *   Listing them here would be the same twelve boxes under a second
     *   name, and a visitor who found both would reasonably wonder which
     *   one is the real page.
     *
     *   sounds_count > 0 — an empty collection in a directory is a row that
     *   costs a click to find out it was nothing.
     */
    public function scopeListed($query)
    {
        return $query->where('is_listed', true)
            ->where('is_public', true)
            ->notPack()
            ->where('sounds_count', '>', 0);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Keeps the denormalised counters honest. Called after any attach or
     * detach, so listings never have to count rows.
     *
     * premium_count rides along with sounds_count rather than being
     * refreshed by its own method, because the two are only ever wrong at
     * the same moment — when the contents change. Two methods would mean one
     * of them eventually gets called and the other does not.
     */
    public function refreshCount(): void
    {
        $this->update([
            'sounds_count' => $this->sounds()->count(),
            'premium_count' => $this->sounds()->where('is_premium', true)->count(),
        ]);
    }

    /* ═══════════════════════════ Sharing ═══════════════════════════ */

    /**
     * The address somebody else would open.
     *
     * It exists here rather than being written out at each call site because
     * two screens offer the link and a third will, and a URL assembled in
     * three places is a URL that eventually disagrees with itself.
     */
    public function shareUrl(): string
    {
        return route('collections.show', $this);
    }

    /**
     * The three states as one word, read from the two columns that store it.
     *
     * Every screen asks this and none of them read is_public or is_listed
     * directly. The pair is storage; this is the idea.
     */
    public function visibility(): string
    {
        if (! $this->is_public) {
            return self::PRIVATE;
        }

        return $this->is_listed ? self::LISTED : self::UNLISTED;
    }

    public function isListed(): bool
    {
        return $this->visibility() === self::LISTED;
    }

    /** Can somebody who was handed the link open it? */
    public function isOpenByLink(): bool
    {
        return $this->visibility() !== self::PRIVATE;
    }

    /**
     * The three choices, described once.
     *
     * The library row and the collection's own page both draw this menu, and
     * the wording of what each state means is the part most likely to drift
     * — so the labels, the icons and the sentences live here rather than in
     * two templates. Icons are written out in full because they are read by
     * a Blade component that cannot know a name assembled at runtime.
     *
     * @return array<string, array{label: string, icon: string, blurb: string}>
     */
    public static function visibilities(): array
    {
        return [
            self::PRIVATE => [
                'label' => 'Private',
                'icon' => 'lock',
                'blurb' => 'Only you. The link does not open for anybody else.',
            ],
            self::UNLISTED => [
                'label' => 'Unlisted',
                'icon' => 'link',
                'blurb' => 'Anybody you send the link to can open it. It is not in the directory and search engines never see it.',
            ],
            self::LISTED => [
                'label' => 'Listed',
                'icon' => 'globe',
                'blurb' => 'Shown in the public collections directory, where anybody can find it.',
            ],
        ];
    }

    /**
     * Move it to one of the three states, or refuse and say why.
     *
     * ── WHY THE NAME IS CHECKED HERE ─────────────────────────────────────
     *
     * The name is checked when the collection is created, but a private one
     * can be renamed afterwards — or can predate the check existing at all.
     * Leaving private is the moment it stops being nobody else's business,
     * so it is the moment the question is worth asking a second time.
     * Returning TO private is never refused: taking something off the web is
     * not something to stand in the way of.
     *
     * ── WHY IT RETURNS THE WHOLE SENTENCE ────────────────────────────────
     *
     * It used to return just the offending word and each screen wrote its own
     * refusal around it. Two screens, two sentences, and nothing keeping them
     * in step. The sentence is part of the rule, so it lives with the rule.
     *
     * @return string|null  null when it moved, the refusal when it did not
     */
    public function setVisibility(string $to): ?string
    {
        if (! array_key_exists($to, self::visibilities())) {
            return 'That is not a state a collection can be in.';
        }

        if ($to === $this->visibility()) {
            return null;
        }

        if ($to !== self::PRIVATE) {
            $word = CleanWords::hit($this->name);

            if (filled($word)) {
                return "“{$this->name}” cannot leave private while it contains “{$word}”. Rename it first.";
            }
        }

        /*
         * Both columns, always, in one write.
         *
         * is_listed is meaningless without is_public, and the combination
         * "private but listed" would be a row the directory query believes
         * and the collection page 404s on. It cannot happen as long as this
         * is the only place either column is written.
         */
        $this->update([
            'is_public' => $to !== self::PRIVATE,
            'is_listed' => $to === self::LISTED,
        ]);

        return null;
    }

    /**
     * Do search engines get to keep this page?
     *
     * Two conditions, and the owner only controls one of them. A listed
     * collection is visible to people; whether it is visible to Google is a
     * decision about the whole domain — dozens of collections named
     * "Podcast" competing with the catalogue is dbelo's problem, not the
     * owner's — so it is an admin setting, off by default.
     *
     * Private and unlisted are never indexable, whatever the setting says.
     */
    public function isIndexable(): bool
    {
        return $this->isListed() && filter_var(
            Setting::read('collections.indexable', false),
            FILTER_VALIDATE_BOOLEAN
        );
    }

    /* ═══════════════════════════ The cover ═══════════════════════════ */

    /**
     * The uploaded cover, or null when the pack has none.
     *
     * Null is a normal answer and the listing is built to handle it: a pack
     * without a cover draws the icon treatment instead. That is deliberate —
     * requiring an image before a pack can look finished means twelve packs
     * have to be designed before any of them can launch.
     */
    public function coverUrl(): ?string
    {
        if (blank($this->cover_path)) {
            return null;
        }

        return Storage::disk(config('dbelo.storage.media', 'public'))->url($this->cover_path);
    }

    public function hasCover(): bool
    {
        return filled($this->cover_path);
    }

    /* ═══════════════════════════ Free or paid ═══════════════════════════ */

    /**
     * Is any of this pack behind a subscription?
     *
     * Read from the counter, never from the rows. A listing renders twelve of
     * these and the whole point of premium_count is that it does not become
     * twelve extra queries.
     */
    public function hasPremium(): bool
    {
        return (int) $this->premium_count > 0;
    }

    /** Every sound in it is paid for — not just some. */
    public function isFullyPremium(): bool
    {
        return $this->sounds_count > 0 && (int) $this->premium_count >= (int) $this->sounds_count;
    }

    /**
     * The badge, as three facts rather than a rendered string.
     *
     * ── WHY THERE ARE THREE STATES AND NOT TWO ───────────────────────────
     *
     * "Free" and "Pro" would be a lie on the common case. Most packs are
     * mostly free with a handful of paid sounds in them, and calling that
     * "Pro" makes a visitor skip a pack they could have used nine tenths of
     * — while calling it "Free" is a promise the download page breaks.
     *
     * So a mixed pack says how many are paid, which is the only honest
     * version and also the most useful one.
     *
     * @return array{label: string, tone: string, title: string}
     */
    public function badge(): array
    {
        if (! $this->hasPremium()) {
            return [
                'label' => 'Free',
                'tone' => 'success',
                'title' => 'Every sound in this pack is free to download.',
            ];
        }

        if ($this->isFullyPremium()) {
            return [
                'label' => 'Pro',
                'tone' => 'brand',
                'title' => 'Every sound in this pack needs a subscription.',
            ];
        }

        return [
            'label' => $this->premium_count.' Pro',
            'tone' => 'brand',
            'title' => $this->premium_count.' of '.$this->sounds_count.' sounds need a subscription. The rest are free.',
        ];
    }

    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'collection';
        $slug = $base;
        $i = 2;

        while (static::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }
}
