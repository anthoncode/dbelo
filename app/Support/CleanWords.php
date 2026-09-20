<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * A name check for anything a visitor types that another visitor might read.
 *
 * ── WHAT THIS IS FOR, AND WHAT IT IS NOT ─────────────────────────────────
 *
 * Collections are named by users and can be shared by link. A name lives on
 * dbelo's domain, so a slur in one is dbelo hosting a slur — which matters
 * regardless of who typed it.
 *
 * This is NOT content moderation and will never be. It is a doormat: it
 * stops the ninety-five per cent who are idly testing what the box accepts,
 * and it will not stop anybody who actually tries. A word list is beaten by
 * a space, a zero for an o, or a language nobody thought of, and building
 * something that could not be beaten would mean rejecting real names for
 * real projects — which costs a user their work to prevent a word.
 *
 * So it is deliberately shallow, and the real safety net is elsewhere:
 * shared collections are never indexed and never listed, so the audience for
 * a bad name is whoever was handed the link.
 *
 * ── WHY THE MATCH IS ON WHOLE WORDS ──────────────────────────────────────
 *
 * Substring matching is how "Scunthorpe" gets banned, and the class of bug
 * is famous enough to carry that town's name. Every entry here is matched as
 * a word with boundaries either side, so "classic" survives a list that
 * contains a three-letter word inside it.
 */
class CleanWords
{
    /**
     * Matched as whole words, accent-folded and lower-cased first.
     *
     * Spanish and English, because those are the two languages the catalogue
     * and its audience actually use. Adding a third would be adding one
     * nobody here can review, and a filter that rejects a word no one can
     * explain is worse than no filter.
     *
     * Kept short on purpose. Every entry is a name somebody cannot use, and
     * the long lists found online are full of medical terms, place names and
     * ordinary words that happen to offend in one dialect.
     *
     * @var array<int, string>
     */
    private const BLOCKED = [
        // English
        'fuck', 'fucking', 'fucker', 'shit', 'bitch', 'cunt', 'whore',
        'slut', 'nigger', 'nigga', 'faggot', 'retard', 'rapist',
        // Spanish
        'puta', 'putas', 'puto', 'putos', 'mierda',
        'pendejo', 'pendeja', 'culero', 'chinga', 'chingar',
        'maricon', 'maricón', 'joto', 'cabron', 'cabrón', 'gilipollas',
    ];

    /*
     * ── WORDS DELIBERATELY LEFT OUT, AND WHY ─────────────────────────────
     *
     * Every one of these was on the list until it was tested against names a
     * real person might give a real collection. This is a SOUND LIBRARY: the
     * words it deals in are objects and animals, which is exactly where a
     * profanity list does its worst damage.
     *
     *   zorra    a fox. There is no version of a sound catalogue where this
     *            is not a legitimate collection name.
     *   concha   a shell. "Concha de nácar" is a thing, and it is also a
     *            woman's name.
     *   polla    vulgar in Spain, a young hen everywhere else.
     *   pija     vulgar in the Southern Cone, "posh" in Spain.
     *   rape     the word matters in English and is a fish in Spanish, plus
     *            "al rape" is a haircut. rapist stays; this does not.
     *   cono     a cone. See the note on EXACT below — this is the whole
     *            reason that list exists.
     *
     * The rule that produced this list: when a word is vulgar in one dialect
     * and ordinary in another, the ordinary reading wins. A false positive
     * costs a user their collection name and a support message; a false
     * negative costs a rude name on a page nobody can find without a link.
     */

    /**
     * Matched WITHOUT folding accents away.
     *
     * The main list is accent-folded so "maricón" and "maricon" are one
     * entry. That same folding turns "coño" into "cono" — a traffic cone, a
     * pine cone, a perfectly good name for a collection of sounds. Folding
     * is right for most entries and catastrophic for this one.
     *
     * So these are compared against the text with its accents intact. The
     * cost is that somebody typing it without the ñ walks through, which is
     * the trade this whole class already accepts.
     *
     * @var array<int, string>
     */
    private const EXACT = [
        'coño', 'coños',
    ];

    /**
     * Is this name usable?
     *
     * Note what it does NOT do: it does not clean the name up, or replace
     * anything with asterisks. Silently altering what somebody typed is
     * worse than refusing it — they never learn why their collection is
     * called something else now.
     */
    public static function ok(?string $text): bool
    {
        return self::hit($text) === null;
    }

    /**
     * The first blocked word found, or null.
     *
     * Returned rather than a bare boolean so the error message can name it.
     * "That name contains a word we do not allow" with no indication of
     * which word is the message that makes somebody retype the same thing
     * three times.
     */
    public static function hit(?string $text): ?string
    {
        $text = (string) $text;

        $folded = self::normalise($text, ascii: true);
        $intact = self::normalise($text, ascii: false);

        foreach ([[self::BLOCKED, $folded], [self::EXACT, $intact]] as [$list, $haystack]) {
            if ($haystack === '') {
                continue;
            }

            foreach ($list as $word) {
                $needle = self::normalise($word, ascii: $haystack === $folded);

                if ($needle === '') {
                    continue;
                }

                // \b is a word boundary: the needle has to stand alone rather
                // than sit inside a longer, innocent word. See the Scunthorpe
                // note above — "Shitake mushrooms" passes because of this.
                if (preg_match('/\b'.preg_quote($needle, '/').'\b/u', $haystack) === 1) {
                    return $word;
                }
            }
        }

        return null;
    }

    /**
     * Lower-cased, separators turned into spaces, accents optional.
     *
     * ── A CLAIM THAT WAS IN THIS COMMENT AND WAS FALSE ───────────────────
     *
     * It used to say the separator step "stops p.u.t.a from walking past a
     * word-boundary match". Testing it showed the opposite: turning the dots
     * into spaces leaves "p u t a", which contains no such word, so the
     * spaced-out spelling sails through. The step is still right — it is
     * what catches "puta_de" and "puta-madre" — but it does nothing about
     * letters pulled apart, and the comment was describing a defence that
     * was not there.
     *
     * Left as it is rather than "fixed". Catching "p u t a" means collapsing
     * single letters, which then reads "S O S alarm" as a word too. That is
     * the arms race this class opens by saying it will not run.
     */
    private static function normalise(string $text, bool $ascii = true): string
    {
        $text = $ascii ? Str::lower(Str::ascii($text)) : Str::lower($text);

        // Anything that is not a letter or a digit becomes a space, so word
        // boundaries fall where a reader would put them.
        $text = preg_replace($ascii ? '/[^a-z0-9]+/u' : '/[^\p{L}\p{N}]+/u', ' ', $text);

        return trim(preg_replace('/\s+/', ' ', (string) $text));
    }
}
