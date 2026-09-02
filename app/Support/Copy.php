<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

/**
 * Editable headline text, with one word allowed to shout.
 *
 * The landing page headlines carry a highlighted word — "Every sound your
 * *story* needs" — and that highlight is part of the design, not decoration
 * somebody typed. So it has to survive being edited from the panel.
 *
 * IT IS NOT STORED AS HTML, and that is the entire point of this class.
 *
 * A textarea whose contents are printed with {!! !!} is a stored-XSS hole
 * with a friendly label on it: anyone who can reach the settings screen can
 * put a <script> tag on the front page of the site, and the next person to
 * gain that access inherits it. Storing markup also means an unclosed tag
 * breaks the layout of the homepage with no error anywhere.
 *
 * So the table holds plain text with one convention — *asterisks* around the
 * word to highlight — and the markup is generated here, from a template
 * nobody can edit. Everything else is escaped first, so the worst a hostile
 * value can do is show its own angle brackets on screen.
 *
 * A line break becomes <br>, because "Every sound your / story needs" is two
 * lines on purpose and asking somebody to type <br> defeats the exercise.
 */
class Copy
{
    /**
     * Escape everything, then apply the one thing that is allowed.
     *
     * Order matters: e() first. Running the highlight first would let the
     * escaping turn our own <span> into visible text.
     */
    public static function rich(?string $text): HtmlString
    {
        $safe = e((string) $text);

        // No newline inside the match: a stray asterisk at the top of the
        // box should not swallow half the paragraph looking for its pair.
        $safe = preg_replace(
            '/\*([^*\n]+)\*/',
            '<span class="key">$1</span>',
            $safe,
        );

        return new HtmlString(nl2br($safe, false));
    }

    /**
     * The same text with the markers removed and no markup at all.
     *
     * For the places a headline is reused where tags cannot go: a <title>,
     * a meta description, the subject line of an email.
     */
    public static function plain(?string $text): string
    {
        $flat = str_replace(["\r\n", "\n", "\r"], ' ', (string) $text);

        return trim(preg_replace('/\*([^*\n]+)\*/', '$1', $flat));
    }
}
