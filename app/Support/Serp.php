<?php

namespace App\Support;

/**
 * What a search result will actually look like.
 *
 * ── WHY THIS IS A CLASS AND NOT A FEW LINES IN THE EDITOR ────────────────
 *
 * Because the editor's preview was lying, quietly, in the way that costs
 * most: it drew the title the author typed, and the page emits that title
 * plus " — dbelo". Every title written in that box was measured against a
 * limit it was never going to be judged by, and the eight characters that
 * pushed it over the edge were invisible to the only person who could have
 * shortened it.
 *
 * partials/head.blade.php is where the real title is assembled. This class
 * assembles it the same way, from the same config key, so the preview and
 * the page cannot disagree — and PostSeo asks it how much room is left
 * rather than carrying its own copy of the number.
 *
 * ── WHY WIDTH AND NOT CHARACTER COUNT ────────────────────────────────────
 *
 * Google cuts a result by WIDTH, not by length. "Mi mejor micrófono" and
 * "Illillillillillili" are the same number of characters and nowhere near
 * the same size on screen, so a counter that says 18/60 for both is telling
 * one of them something false.
 *
 * What is measured here is not pixels — nobody here knows the font, the
 * device or the zoom. It is how much of the LINE the text uses, with wide
 * letters counting for more than narrow ones. Calibrated so ordinary prose
 * agrees with the familiar rules of thumb (about sixty characters of title,
 * about a hundred and fifty-five of description) and disagrees with them
 * exactly where those rules are wrong.
 */
class Serp
{
    /** The same separator partials/head.blade.php uses. */
    public const SEPARATOR = ' — ';

    /**
     * The width of a full line, in units where a lowercase "n" is 1.
     *
     * Chosen so that a title of about sixty characters of normal text fills
     * the title line, and a description of about a hundred and fifty-five
     * fills the description — the numbers every SEO tool quotes, which are
     * themselves averages of exactly this measurement.
     */
    public const TITLE_UNITS = 57.0;

    public const DESCRIPTION_UNITS = 147.0;

    /**
     * Below this share of the line, the text is not wrong — it is just
     * leaving room nobody else can use. Yoast paints that orange rather than
     * red for the same reason: a short description is a missed opportunity,
     * not a mistake.
     */
    public const SHORT_AT = 0.62;

    /**
     * Relative widths. A lowercase "n" is 1.
     *
     * Not a font metric and not pretending to be one. Four classes is enough
     * to separate "this will fit" from "this will be cut", which is the only
     * question the bar is being asked.
     */
    private const NARROW = 'iljItfr.,;:!|\'`"()[]{}/\\-';

    private const WIDE = 'mwMW@%';

    public static function siteName(): string
    {
        return (string) config('app.name', 'dbelo');
    }

    /** What the site appends to every page title. */
    public static function suffix(): string
    {
        return self::SEPARATOR.self::siteName();
    }

    /** The title as the page will really emit it. */
    public static function fullTitle(string $title): string
    {
        $title = trim($title);

        return $title === ''
            ? self::siteName().' — sound effects library'
            : $title.self::suffix();
    }

    /**
     * Characters left for the author once the site has taken its share.
     *
     * A plain count, deliberately, because this number is written into a
     * prompt and a model cannot be handed a weight table. The bar on screen
     * measures width; this is the instruction, and it is the conservative
     * one of the two.
     */
    public static function titleCharBudget(int $fullBudget = 60): int
    {
        return max(20, $fullBudget - mb_strlen(self::suffix()));
    }

    /**
     * How much of the line this text uses. 1.0 is exactly full.
     *
     * Over 1 is not an error and is not stopped anywhere — Google cuts it,
     * and sometimes a cut tail is a fair price. It is shown, not prevented.
     */
    public static function fill(string $text, string $kind = 'title'): float
    {
        $limit = $kind === 'description' ? self::DESCRIPTION_UNITS : self::TITLE_UNITS;

        return $limit > 0 ? self::width($text) / $limit : 0.0;
    }

    /** @return string  short | good | long */
    public static function verdict(string $text, string $kind = 'title'): string
    {
        $fill = self::fill($text, $kind);

        if ($fill > 1.0) {
            return 'long';
        }

        return $fill < self::SHORT_AT ? 'short' : 'good';
    }

    /** Width in units where a lowercase "n" is 1. */
    public static function width(string $text): float
    {
        $total = 0.0;
        $length = mb_strlen($text);

        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($text, $i, 1);

            $total += match (true) {
                $char === ' ' => 0.45,
                str_contains(self::NARROW, $char) => 0.45,
                str_contains(self::WIDE, $char) => 1.7,
                // Anything outside ASCII is left at 1: accented Latin is the
                // common case here and an "á" is an "a". Guessing at scripts
                // this site does not publish in would be invention.
                preg_match('/[A-ZÁÉÍÓÚÑÜ]/u', $char) === 1 => 1.25,
                default => 1.0,
            };
        }

        return $total;
    }
}
