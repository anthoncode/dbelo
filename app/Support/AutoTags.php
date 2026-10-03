<?php

namespace App\Support;

use App\Models\Sound;
use App\Models\Tag;
use Illuminate\Support\Str;

/**
 * Tags derived from what dbelo already knows, with no model involved.
 *
 * ── WHY THIS EXISTS ALONGSIDE THE AI SUGGESTER ───────────────────────────
 *
 * A sound with no tags is a sound the search barely finds. Until now nothing
 * created any: the importer never touched the tags table and FilenameMeta
 * only ever returned a title, a category and a confidence — so an imported
 * catalogue sat at zero tags and nobody had done anything wrong.
 *
 * The model fixes that when it answers. This fixes it when it does not: no
 * API key, the provider down, a quota spent, or an answer that came back with
 * two words in it. Those are all normal states, and none of them should leave
 * a sound unfindable.
 *
 * It is also free and instant, which means it can run over the whole existing
 * catalogue without spending anything.
 *
 * ── WHAT IT WILL NOT DO ──────────────────────────────────────────────────
 *
 * It does not invent. Every word it returns is already in the title or is the
 * name of the category the sound is filed under. That is the entire point:
 * whatever else can be said about a tag taken from the filename, it cannot be
 * a hallucination.
 */
class AutoTags
{
    /** Below this, a sound is treated as untagged. */
    public const MINIMUM = 3;

    /**
     * The ceiling for tags NOBODY TYPED: what the model proposed, plus
     * whatever the filename and the category top it up with.
     *
     * Not a limit on tagging. A person can still add more by hand, up to
     * Tag::MAX_PER_SOUND — this is a limit on guessing, and the two are
     * different permissions.
     *
     * It lives here rather than inside SoundSuggester because two callers
     * need it: the model's own answer is cut to this length, and so is the
     * list after the top-up. With the number in one place a thin answer
     * plus a generous top-up cannot quietly add up to nine.
     *
     * Five, because Tag::MAX_PER_SOUND already makes the argument in its
     * own docblock: past a point the extra terms match everything and stop
     * distinguishing anything, which makes the whole catalogue worse rather
     * than that one sound better.
     */
    public const MAX_AUTOMATIC = 5;

    /**
     * Words that are in almost every filename and mean nothing as a search
     * term. A tag that matches four hundred sounds is not a filter.
     */
    private const NOISE = [
        // grammar
        'the', 'a', 'an', 'and', 'or', 'of', 'in', 'on', 'at', 'to', 'for',
        'with', 'from', 'by', 'into', 'out', 'up', 'off', 'vs',
        // the words every audio file already says about itself
        'sound', 'sounds', 'sfx', 'fx', 'effect', 'effects', 'audio', 'noise',
        'wav', 'mp3', 'aiff', 'flac', 'ogg', 'file', 'clip', 'sample',
        'take', 'takes', 'final', 'edit', 'edited', 'master', 'mastered',
        'mix', 'mixdown', 'render', 'export', 'new', 'old', 'copy', 'untitled',
        'stereo', 'mono', 'khz', 'bit', 'db',
    ];

    /**
     * @return array<int, string> lower-case, de-duplicated, at most $limit
     */
    public static function forSound(Sound $sound, int $limit = 8): array
    {
        $words = self::fromTitle((string) $sound->title, $sound->user?->name);

        /*
         * The category goes in as a tag too, and its parent with it.
         *
         * Someone searching "ambience" should find a sound filed under
         * Ambience even when the filename never used the word — which is
         * most of them, because a person naming a file writes what the sound
         * IS, not which drawer it lives in.
         */
        foreach ([$sound->category?->name, $sound->category?->parent?->name] as $name) {
            if (filled($name)) {
                $words = array_merge($words, self::fromTitle((string) $name, null));
            }
        }

        return collect($words)
            ->unique()
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * The usable words in a piece of text.
     *
     * @param  string|null  $exclude  a name whose words must not become tags
     * @return array<int, string>
     */
    public static function fromTitle(string $text, ?string $exclude = null): array
    {
        // Contributor names turn up at the front of a lot of imported
        // filenames — "Alexzavesa Calm Elegant Logo 519008" — and a person's
        // name is the one word in there nobody will ever search for.
        $banned = $exclude
            ? collect(preg_split('/[^\p{L}]+/u', Str::lower($exclude), -1, PREG_SPLIT_NO_EMPTY))->all()
            : [];

        $pieces = preg_split('/[^\p{L}\p{N}]+/u', Str::lower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return collect($pieces)
            /*
             * TRIM DIGITS OFF THE ENDS BEFORE JUDGING THE WORD.
             *
             * This used to throw away anything containing a digit, which is
             * right for "254773" and badly wrong for "Swoosh14" — a real
             * title from this catalogue that produced exactly one tag,
             * because the only usable word in it had a take number welded on.
             * Numbering runs into the word far more often than anybody types
             * a space before it.
             *
             *   swoosh14 → swoosh      take2 → take (then dropped as noise)
             *   254773   → ''          44khz → khz  (then dropped as noise)
             *
             * A digit still stuck in the MIDDLE is a code, not a word, and
             * the next line drops it.
             */
            ->map(fn ($word) => preg_replace('/^\d+|\d+$/', '', $word))
            ->reject(fn ($word) => preg_match('/\d/', $word) === 1)
            // Two letters is not a word worth filtering a catalogue by.
            ->filter(fn ($word) => mb_strlen($word) > 2)
            ->reject(fn ($word) => in_array($word, self::NOISE, true))
            ->reject(fn ($word) => in_array($word, $banned, true))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Give a sound tags if it has fewer than the minimum, WITHOUT removing
     * any it already has.
     *
     * syncWithoutDetaching on purpose. This runs after an import, after a
     * conversion and from a backfill command, and a person may have typed
     * tags in between any two of those. Something that tops up must never be
     * able to delete.
     *
     * @return int how many were added
     */
    public static function topUp(Sound $sound, int $minimum = self::MINIMUM): int
    {
        $sound->loadMissing(['tags', 'category.parent', 'user']);

        if ($sound->tags->count() >= $minimum) {
            return 0;
        }

        $names = self::forSound($sound);

        if ($names === []) {
            return 0;
        }

        return self::attach($sound, $names);
    }

    /**
     * Add these tag names to a sound, creating any that do not exist.
     *
     * Goes through Tag::idsFromList so the slugging, the de-duplication and
     * the per-sound cap are the same ones the editors use. One definition,
     * several surfaces — otherwise "thunder" typed in admin and "Thunder"
     * added here become two rows that split the catalogue in half.
     *
     * @param  array<int, string>  $names
     * @return int how many were actually new to this sound
     */
    public static function attach(Sound $sound, array $names): int
    {
        $ids = Tag::idsFromList(implode(', ', $names), Tag::MAX_PER_SOUND);

        if ($ids === []) {
            return 0;
        }

        $before = $sound->tags()->count();

        $sound->tags()->syncWithoutDetaching($ids);

        /*
         * ATTACHING A PIVOT ROW DOES NOT FIRE THE MODEL'S saved EVENT.
         *
         * Scout hooks that event, so without this line the tags exist in the
         * database and Meilisearch has never heard of them — the sound is
         * tagged on its page and still unfindable by those words, which is
         * the exact problem this class was written to solve, silently only
         * half fixed.
         *
         * rescue(), because the search engine being down is a normal state on
         * a laptop and must not take a 128-sound backfill with it. The index
         * catches up the next time the sound is saved or reindexed.
         */
        if ($sound->shouldBeSearchable()) {
            rescue(fn () => $sound->searchable(), null, false);
        }

        // Counted rather than assumed: syncWithoutDetaching happily re-adds
        // nothing, and reporting "8 added" when 8 were already there is how a
        // backfill looks successful while doing nothing.
        return max(0, $sound->tags()->count() - $before);
    }
}
