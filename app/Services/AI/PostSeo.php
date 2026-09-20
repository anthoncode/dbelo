<?php

namespace App\Services\AI;

use App\Support\Serp;
use Illuminate\Support\Str;

/**
 * The title and the description as a search result will show them.
 *
 * ── WHY THESE TWO ARE ASKED FOR TOGETHER ─────────────────────────────────
 *
 * Because they are read together. A result is three lines — address, title,
 * description — and the description's job is to say what the title did not
 * have room for. Generated separately they repeat each other: the same six
 * words, once in blue and once in grey, and the second line wastes the only
 * space on the page where this article gets to argue for itself.
 *
 * ── THE META TITLE IS THE SAME CLAIM, NOT A SECOND ONE ───────────────────
 *
 * This class used to argue that the meta title was free to be a different
 * idea from the headline, on the grounds that the two have different
 * readers. The readers are different — one has arrived, one is scanning ten
 * blue lines — but the conclusion was wrong, and Google's own documentation
 * is what corrects it.
 *
 * The title element is not the only thing Google reads to build the title
 * link. It also reads the H1, the main visual title, prominent text, and the
 * anchor text of links pointing at the page. And "inaccurate <title>
 * elements" — ones that do not reflect what is actually on the page — are
 * listed as a reason Google REPLACES the title with something of its own.
 *
 * So a meta title written as a separate creative exercise is a meta title
 * that may never be shown. The effort spent on it is spent twice: once
 * writing it, once on the page it no longer describes.
 *
 * What is left is narrower and still worth doing: the same claim, trimmed to
 * a narrow line and front-loaded, because the end is what gets cut. Same
 * subject, same words a reader would have typed. A different sentence, never
 * a different promise.
 *
 * Left empty, the site falls back to the headline — a fine default, and the
 * reason this is a suggestion and not a requirement.
 *
 * ── AND THE SITE NAME BELONGS THERE ──────────────────────────────────────
 *
 * Google's guidance is to "consider including just your site name at the
 * beginning or end of each <title> element, separated from the rest of the
 * text with a delimiter", which is exactly what partials/head.blade.php
 * does. It comes out of the same line's budget — see titleMax().
 */
class PostSeo
{
    /**
     * Google renders a title in about 600 pixels and a description in about
     * 990, and it measures PIXELS — these are guides, not laws. What they
     * protect against is the real failure: the cut landing in the middle of
     * the part that said what the page is.
     *
     * The description floor exists because ninety characters leaves a third
     * of the line empty. That space is free, nobody else can use it, and a
     * description that does not fill it is arguing with one hand down.
     */
    public const TITLE_MAX = 60;

    public const DESCRIPTION_MIN = 140;

    public const DESCRIPTION_MAX = 158;

    /**
     * The excerpt: the summary people read, not the one Google reads.
     *
     * Longer than a meta description because it has a different reader. The
     * description talks to somebody who has not arrived yet and is fighting
     * for one click; the excerpt talks to somebody already on the page — it
     * is printed large under the title — and it can breathe.
     *
     * The listing cuts it at 120 characters, which is the constraint that
     * actually shapes it: whatever comes after that has to be a bonus, and
     * the first sentence has to survive on its own.
     */
    public const EXCERPT_MAX = 220;

    public const EXCERPT_LISTING_CUT = 120;

    /** Enough of the draft to know what the page delivers. */
    private const BODY_CHARS = 2500;

    /**
     * What the author's own field may hold.
     *
     * The page emits "<this> — dbelo" — see partials/head.blade.php — so the
     * site's half of the title comes out of the same sixty. Asking the model
     * for sixty characters and then adding eight is how every title in the
     * blog ends up two words too long, with the two words that got cut being
     * the ones at the end the author thought were safe.
     *
     * Read from App\Support\Serp rather than hard-coded, because the site
     * name is a setting and can change on any Tuesday.
     */
    public static function titleMax(): int
    {
        return Serp::titleCharBudget(self::TITLE_MAX);
    }

    public function __construct(private ?AiProvider $provider = null) {}

    public function provider(): AiProvider
    {
        return $this->provider ??= SoundSuggester::driver();
    }

    /**
     * @return array{meta_title: string, meta_description: string, excerpt: string, why: string, provider: string, model: string}
     *
     * @throws AiException
     */
    public function for(string $title, string $excerpt = '', string $body = '', string $type = 'post'): array
    {
        $answer = $this->provider()->complete(
            $this->system(),
            $this->facts($title, $excerpt, $body, $type),
            $this->schema(),
        );

        return [
            'meta_title' => $this->cleanTitle($answer['meta_title'] ?? null),
            'meta_description' => $this->cleanDescription($answer['meta_description'] ?? null),
            'excerpt' => $this->cleanExcerpt($answer['excerpt'] ?? null),
            'why' => Str::limit($this->tidy($answer['why'] ?? null), 160, '…'),
            'provider' => $this->provider()->name(),
            'model' => $this->provider()->model(),
        ];
    }

    /* ═══════════════════════════ The prompt ═══════════════════════════ */

    private function system(): string
    {
        $titleMax = self::titleMax();
        $suffix = Serp::suffix();
        $site = Serp::siteName();
        $min = self::DESCRIPTION_MIN;
        $max = self::DESCRIPTION_MAX;
        $excerptMax = self::EXCERPT_MAX;
        $listingCut = self::EXCERPT_LISTING_CUT;

        return <<<PROMPT
        You write the search-result snippet for an article on dbelo, a sound
        effects library. You are given the author's headline and the opening
        of their draft. You have read the draft.

        You are writing three things: the two lines a person sees in Google
        before they have seen the page — a title and a description — and the
        EXCERPT, which is the summary shown to people already on the site.
        Three fields, three readers, and the third is not a copy of the
        second.

        HARD RULES, in order of importance:

        1. NEVER PROMISE WHAT THE DRAFT DOES NOT DELIVER. No number the
           article does not contain, no comparison it does not make, no
           broader subject than it covers. A snippet that oversells is a
           click that leaves immediately, and leaving is measured.
        2. THE TITLE IS THE HEADLINE, TRIMMED — NOT A SECOND HEADLINE.
           Keep the subject and keep the words a reader would have typed. You
           may shorten it, reorder it so the important words come first, and
           drop a flourish that does not survive a narrow line. You may not
           change what it claims, swap its subject, or make a promise the
           headline does not make.
           This is not a style rule. Google builds the search title from the
           title element AND from the page's own heading, and replaces a
           title element it judges inaccurate — so a title that argues with
           the headline is a title nobody will ever see.
        3. LENGTH: at most {$titleMax} characters, and that is the budget for
           YOUR HALF ONLY. The site appends "{$suffix}", and the two halves
           share one line. Do not write "{$site}" yourself, do not add a
           separator, do not end with a dash — all three arrive on their own
           and would then be there twice.
           Put the important words near the FRONT, because it is the END that
           gets cut.
        4. THE DESCRIPTION: between {$min} and {$max} characters, and aim near
           the top of that range. A result is given about 990 pixels of width
           and a description of ninety characters leaves a third of it empty —
           free space nobody else can use.
        5. The description must say what the READER GETS, not what the article
           is about. "How to record rain indoors" is the subject. "Three
           microphone placements for rain, and the one that stops it sounding
           like static" is what they get. It may ask a question or name the
           mistake the article fixes.
        6. The description must NOT repeat the title's words. It has one line
           to add what the title had no room for. If both say the same six
           words, the second line has been wasted.
        7. WRITE IN THE SAME LANGUAGE AS THE DRAFT. If the draft is in
           Spanish, both fields and the reason are in Spanish.
        8. No quotation marks around either field, no emoji, no ALL CAPS, no
           exclamation marks.

        THE EXCERPT, which is a different job from the description:

        9. It is printed in full under the title on the article page, cut to
           {$listingCut} characters in the blog listing, and sent at 300 to
           the RSS feed. So: at most {$excerptMax} characters, and THE FIRST
           SENTENCE MUST STAND ALONE UNDER {$listingCut} CHARACTERS, because
           in the listing that is all anybody sees. What comes after it is a
           bonus for the reader who reached the article.
        10. Its reader has already arrived. It is not fighting for a click, so
           it does not repeat the description's argument — it sets up what is
           about to be read. Plain sentences, the author's register, full
           stops where sentences end.
        11. Do not open it with the article's own title, and do not open it
           with the article's first sentence copied across. A summary that is
           the opening line is one paragraph printed twice on one page.

        BANNED outright, because every page on the internet already uses them
        and they say nothing: "the ultimate guide", "everything you need to
        know", "in this article we", "learn more about", "discover", "dive
        into", "high quality", "perfect for", "read on".

        THE REASON:

        12. One short line for the author saying what the set is doing —
           which term leads, what the description adds that the title could
           not. It explains the CHOICE, not the article. Do not praise it.
        PROMPT;
    }

    private function facts(string $title, string $excerpt, string $body, string $type): string
    {
        $lines = [
            'Kind: '.($type === 'page' ? 'a static page on the site' : 'a blog article'),
            'Headline on the page: '.(trim($title) !== '' ? $title : '(none written yet)'),
        ];

        if (trim($excerpt) !== '') {
            $lines[] = 'Author\'s summary: '.trim($excerpt);
        }

        $draft = trim($body);

        $lines[] = $draft !== ''
            ? "Opening of the draft:\n".Str::limit($draft, self::BODY_CHARS, '…')
            : 'The draft is empty, so work from the headline alone and stay close to it.';

        return implode("\n\n", $lines);
    }

    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'meta_title' => ['type' => 'string'],
                'meta_description' => ['type' => 'string'],
                'excerpt' => ['type' => 'string'],
                'why' => ['type' => 'string'],
            ],
            'required' => ['meta_title', 'meta_description', 'excerpt', 'why'],
            'additionalProperties' => false,
        ];
    }

    /* ═══════════════════════ Trusting nothing ═══════════════════════ */

    private function cleanTitle($value): string
    {
        $title = $this->tidy($value);

        /*
         * Models append the site name anyway, roughly one time in ten,
         * because every title they were trained on has one. Left in, the
         * page would read "… — dbelo — dbelo", and the author would see it
         * only in a search result weeks later.
         */
        $suffix = Serp::suffix();

        if (Str::endsWith($title, $suffix)) {
            $title = trim(Str::beforeLast($title, $suffix));
        }

        $title = trim(rtrim($title, " -–—|·"));

        // Cut on a word. A suggestion that arrives already broken reads as a
        // fault in the tool rather than as a limit of the medium.
        return Str::limit($title, self::titleMax(), '');
    }

    /**
     * Trim to the last full stop inside the window, or on a word.
     *
     * The same rule the sound descriptions use: a sentence that ends before
     * the limit reads as finished, where one that runs exactly to it reads as
     * truncated — and the reader cannot tell whether Google did the cutting.
     */
    private function cleanDescription($value): string
    {
        $text = $this->tidy($value);

        if ($text === '' || mb_strlen($text) <= self::DESCRIPTION_MAX) {
            return $text;
        }

        $window = mb_substr($text, 0, self::DESCRIPTION_MAX);

        foreach (['. ', '? ', '! '] as $stop) {
            $at = mb_strrpos($window, $stop);

            if ($at !== false && $at >= self::DESCRIPTION_MIN - 20) {
                return trim(mb_substr($window, 0, $at + 1));
            }
        }

        return Str::limit($text, self::DESCRIPTION_MAX, '');
    }

    /**
     * Cut on a word if it overruns, and with no ellipsis: the listing adds
     * its own when it truncates at 120, and two sets of dots in one line is
     * how a page looks broken.
     *
     * Full stops are left alone here — unlike a title, a summary is prose
     * and wants them.
     */
    private function cleanExcerpt($value): string
    {
        $text = $this->tidy($value);

        return $text === '' ? '' : Str::limit($text, self::EXCERPT_MAX, '');
    }

    private function tidy($value): string
    {
        if (! is_string($value)) {
            return '';
        }

        $value = trim(preg_replace('/\s+/u', ' ', $value));

        return trim($value, " \t\n\r\0\x0B\"'“”‘’");
    }
}
