<?php

namespace App\Services\AI;

use Illuminate\Support\Str;

/**
 * A pass over the article: heading levels, sentence length, emphasis.
 *
 * ── WHAT THIS IS ALLOWED TO DO, AND WHAT IT IS NOT ───────────────────────
 *
 * It is allowed to change how the article READS. It is not allowed to change
 * what the article SAYS.
 *
 * That line matters more here than anywhere else in the codebase, because a
 * model asked to "improve" prose will improve it into something else: it
 * adds a helpful example that is not true, it rounds four steps up to five,
 * it replaces the author's voice with the voice of every blog post ever
 * scraped. The prompt forbids all of it, and the answer is checked again
 * afterwards, because a prompt is a request and not a guarantee.
 *
 * ── THE CHECK THAT MATTERS MOST ──────────────────────────────────────────
 *
 * Every link and image URL in the original must still be present in the
 * proposal. It is a cheap, mechanical test and it catches the two failures
 * that would be worst to discover after publishing: a model that "tidied" a
 * markdown image into a sentence describing it, and one that rewrote a URL
 * into something plausible and dead. dbelo already has a whole module for
 * dealing with broken links; this is how they do not get written in the
 * first place.
 *
 * ── NOTHING HERE WRITES ──────────────────────────────────────────────────
 *
 * The proposal comes back as a string. The editor shows it beside the
 * original as a diff, and the author's click is what replaces anything.
 */
class PostPolish
{
    /**
     * A polished article is not a different length.
     *
     * Below half or above double the original, something structural
     * happened — a section dropped, or the model wrote an essay of its own —
     * and the honest answer is to refuse rather than to show it as a
     * suggestion worth reading.
     */
    private const MIN_RATIO = 0.55;

    private const MAX_RATIO = 1.9;

    /** Beyond this the article should be split, not polished in one call. */
    public const MAX_CHARS = 14000;

    public function __construct(private ?AiProvider $provider = null) {}

    public function provider(): AiProvider
    {
        return $this->provider ??= SoundSuggester::driver();
    }

    /**
     * @return array{body: string, changes: array<int, string>, provider: string, model: string}
     *
     * @throws AiException
     */
    public function for(string $body, string $title = ''): array
    {
        $original = trim($body);

        $answer = $this->provider()->complete(
            $this->system(),
            $this->facts($original, $title),
            $this->schema(),
        );

        $proposal = $this->check($answer['body'] ?? null, $original);

        return [
            'body' => $proposal,
            'changes' => $this->cleanChanges($answer['changes'] ?? []),
            'provider' => $this->provider()->name(),
            'model' => $this->provider()->model(),
        ];
    }

    /* ═══════════════════════════ The prompt ═══════════════════════════ */

    private function system(): string
    {
        return <<<PROMPT
        You are a copy editor for dbelo, a sound effects library. You are
        given one article in Markdown. You return the SAME article, edited for
        structure and readability.

        You are editing, not writing. The author wrote this.

        FORBIDDEN, and these are the ones that get an edit thrown away:

        1. Do not add a fact, an example, a number, a tool name, a step or a
           claim that is not already in the text. If a section feels thin,
           leave it thin — a missing paragraph is the author's decision to
           make, an invented one is a lie with their name on it.
        2. Do not remove a section, a paragraph or an idea. Merging two short
           paragraphs is editing; dropping the third one is not.
        3. Do not change any URL, any image, any code block, any block quote
           or anything between backticks. Copy them across exactly, character
           for character, including the link text.
        4. Do not translate. Do not change the register the author chose — if
           they write plainly and in the first person, they stay plain and in
           the first person. You are making their sentences easier to read,
           not making them sound like somebody else.
        5. Do not add an introduction, a conclusion, a summary box or a call
           to action that was not there.

        WHAT TO ACTUALLY DO:

        6. HEADINGS. The page already prints the title as the only H1, so the
           article's own headings start at "##". Fix skipped levels — an H4
           under an H2 with no H3 between them. Make a heading say what its
           section is about rather than being a label like "Introduction".
           Do not invent sections to hang new headings on.
        7. PARAGRAPHS. Break a wall of text at the point where the subject
           actually turns. Three to five lines is a paragraph; twelve is a
           page somebody scrolls past.
        8. SENTENCES. Split the ones carrying two ideas. Turn passive
           constructions active where the actor is known. Cut the words that
           carry nothing: "basically", "in order to", "it is important to note
           that", "there is a X that".
        9. EMPHASIS. Bold the phrase a reader skimming would need — the rule,
           the number, the name of the thing. At most two per section. Bold
           everywhere is bold nowhere, and a page of bold reads as shouting.
        10. LISTS. Turn a paragraph that is really a sequence of items into a
           list. Do not turn prose that argues into bullets: an argument
           broken into fragments stops being an argument.

        THE CHANGE LIST:

        11. Return between three and eight short lines saying what you did —
           "split the second section into three paragraphs", "raised the
           headings from H3 to H2", "made four sentences active". They are for
           the author, written in the SAME LANGUAGE AS THE ARTICLE. Never
           describe the article back to them and never say the edit improved
           it. If you changed almost nothing, say that instead of padding the
           list.
        PROMPT;
    }

    private function facts(string $body, string $title): string
    {
        $lines = [];

        if (trim($title) !== '') {
            // Context, explicitly not part of the document: without this the
            // model sometimes adds the title as an H1 at the top, which the
            // page would then print twice.
            $lines[] = 'The page prints this as the title, above the article. It is NOT part of the markdown and must not be added to it: '.trim($title);
        }

        $lines[] = "THE ARTICLE:\n\n".$body;

        return implode("\n\n", $lines);
    }

    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'body' => ['type' => 'string'],
                'changes' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['body', 'changes'],
            'additionalProperties' => false,
        ];
    }

    /* ═══════════════════════ Trusting nothing ═══════════════════════ */

    /**
     * @throws AiException  when the answer is not an edit of what was sent
     */
    private function check($proposal, string $original): string
    {
        if (! is_string($proposal) || trim($proposal) === '') {
            throw AiException::unreadable('editor', 'the answer had no article in it');
        }

        $proposal = trim(str_replace("\r\n", "\n", $proposal));

        /*
         * Markdown is rendered as markdown, and Post::render decides what
         * HTML survives. Even so, a script or a frame arriving from a model
         * is never something the author asked for, and refusing is cheaper
         * than reasoning about the renderer's allow-list.
         */
        if (preg_match('/<\s*(script|iframe|object|embed|form)\b/i', $proposal)) {
            throw AiException::unreadable('editor', 'the answer contained markup that does not belong in an article');
        }

        $before = mb_strlen($original);
        $after = mb_strlen($proposal);

        if ($before > 0 && ($after < $before * self::MIN_RATIO || $after > $before * self::MAX_RATIO)) {
            throw AiException::unreadable(
                'editor',
                'the answer was a different length to the article, so it was not an edit of it'
            );
        }

        $missing = $this->missingUrls($original, $proposal);

        if ($missing !== []) {
            throw AiException::unreadable(
                'editor',
                'the answer lost '.count($missing).' link or image: '.implode(', ', array_slice($missing, 0, 3))
            );
        }

        return $proposal;
    }

    /**
     * Link and image targets present before and absent after.
     *
     * @return array<int, string>
     */
    private function missingUrls(string $original, string $proposal): array
    {
        preg_match_all('/\]\(\s*([^)\s]+)/', $original, $matches);

        $urls = array_unique($matches[1] ?? []);
        $missing = [];

        foreach ($urls as $url) {
            if (! str_contains($proposal, $url)) {
                $missing[] = $url;
            }
        }

        return $missing;
    }

    /**
     * @param  mixed  $changes
     * @return array<int, string>
     */
    private function cleanChanges($changes): array
    {
        if (! is_array($changes)) {
            return [];
        }

        return collect($changes)
            ->filter(fn ($line) => is_string($line))
            ->map(fn ($line) => trim(preg_replace('/\s+/u', ' ', $line)))
            ->map(fn ($line) => rtrim(ltrim($line, "-*• \t"), '.'))
            ->filter(fn ($line) => mb_strlen($line) > 3)
            ->map(fn ($line) => Str::limit($line, 120, '…'))
            ->unique()
            ->take(8)
            ->values()
            ->all();
    }
}
