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

    /**
     * The snippet to emit for a page whose description was written for the
     * page, not for Google.
     *
     * ── WHY THIS EXISTS ──────────────────────────────────────────────────
     *
     * A sound's description used to be sized for the search result: one
     * sentence of about 150 characters, doing double duty as the paragraph
     * a visitor reads. That is the wrong way round. The paragraph belongs to
     * the reader, it is now two or three sentences long, and the snippet is
     * derived from it here.
     *
     * ── WHY BY WIDTH, AND WHY A SENTENCE ─────────────────────────────────
     *
     * Str::limit() counts characters and appends "…", which produces a
     * fragment that announces it was cut — the one thing a result should
     * never look like. This cuts at the last sentence that still fits, so
     * the snippet ends where a person would have ended it, and measures by
     * WIDTH for the reason this whole class exists: Google cuts a line, not
     * a character count.
     *
     * Falls back to a word boundary when the first sentence alone is already
     * too wide, and never mid-word. The ellipsis is kept ONLY in that case,
     * where the text really does continue mid-thought.
     */
    public static function snippet(string $text, float $units = self::DESCRIPTION_UNITS): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text));

        if ($text === '' || self::width($text) <= $units) {
            return $text;
        }

        /*
         * Walked sentence by sentence rather than cut-then-search: the
         * window a character-based cut would open is measured in the wrong
         * unit, so a sentence that fits by width could fall outside it and
         * be thrown away for nothing.
         */
        $kept = '';

        foreach (preg_split('/(?<=[.!?])\s+/u', $text) as $sentence) {
            $candidate = $kept === '' ? $sentence : $kept.' '.$sentence;

            if (self::width($candidate) > $units) {
                break;
            }

            $kept = $candidate;
        }

        /*
         * A FINISHED SENTENCE IS ONLY BETTER WHILE IT STILL FILLS THE LINE.
         *
         * Sentences do not divide evenly into a result line. A paragraph of
         * three — 99 characters, then 91, then 38 — fits the first and
         * overflows on the second, so a rule of "whole sentences only" hands
         * Google 99 characters and leaves a third of the result empty. That
         * is the same waste SHORT_AT exists to warn about, arrived at
         * politely.
         *
         * So the sentence cut is taken only when it fills the line as well
         * as a trimmed phrase would. Below SHORT_AT it loses to the word
         * cut, ellipsis and all: the tail is visibly unfinished, which is
         * honest, and the words that fill the remaining third are words a
         * reader can use.
         */
        if ($kept !== '' && self::fill($kept, 'description') >= self::SHORT_AT) {
            return $kept;
        }

        // Trim whole words off the end until the text plus its ellipsis
        // fits. Never mid-word: a cut word does not read as an editorial
        // decision, it reads as a bug, in the first thing anybody sees.
        $words = explode(' ', $text);

        while (count($words) > 1 && self::width(implode(' ', $words).'…') > $units) {
            array_pop($words);
        }

        $trimmed = rtrim(implode(' ', $words), " ,;:-–—").'…';

        // Unless the sentence we already had is longer than what trimming
        // achieved, in which case the ellipsis bought nothing.
        return $kept !== '' && self::width($kept) >= self::width($trimmed)
            ? $kept
            : $trimmed;
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
