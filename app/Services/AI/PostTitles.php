<?php

namespace App\Services\AI;

use App\Support\Suggestions;
use Illuminate\Support\Str;

/**
 * Three alternative titles for a post, each with the reason it is different.
 *
 * ── WHY THREE WITH REASONS, AND NOT ONE REPLACEMENT ──────────────────────
 *
 * A button that replaces the title with a "better" one is a button that has
 * to be trusted blind. There is no way to tell, from the result alone,
 * whether the model understood the article or just reworded the words it was
 * given — and the title is the single line of a post that the author cares
 * most about.
 *
 * Three options make the choice visible. The reason attached to each is what
 * turns the panel from a lottery into something you can disagree with: an
 * option whose reason is "puts the search term first" can be accepted for
 * that reason or rejected for it, and either way the author learns what the
 * suggestion was FOR.
 *
 * Nothing here writes. The component decides what to do with the answer, and
 * the author decides whether the component gets to.
 *
 * ── THE MODEL HAS READ THE ARTICLE, WHICH IS THE DIFFERENCE ──────────────
 *
 * SoundSuggester exists under a hard rule: the model has NOT heard the audio,
 * so it must never state a fact the filename does not already claim. Here the
 * opposite is true — the draft is supplied, so the suggestions can be about
 * what the piece actually says. The constraint that replaces it is narrower
 * and just as firm: the title may reframe the article, never re-subject it.
 * "Five ways to record rain" must not come back as "Ten ways to record rain"
 * because the model felt ten was a rounder number.
 */
class PostTitles
{
    /** Enough to choose from, few enough to read in one glance. */
    public const COUNT = 3;

    /**
     * Google renders a title in a box about 600 pixels wide, which is roughly
     * sixty characters of normal prose — and it measures PIXELS, so this is a
     * guide and not a law. What it protects against is the real failure: a
     * title cut mid-word in a search result, where the half that got cut was
     * the half that said what the article is.
     */
    public const MAX_LENGTH = 60;

    /**
     * How much of the draft to send.
     *
     * The opening of an article is what a title has to match, and it is also
     * the part a writer has thought hardest about. Sending the whole body
     * would cost more, take longer, and let a digression near the end pull
     * the suggestions away from what the piece is actually about.
     */
    private const BODY_CHARS = 2000;

    public function __construct(private ?AiProvider $provider = null) {}

    public function provider(): AiProvider
    {
        // The same resolution the sound suggester uses, on purpose: one
        // configured provider, one set of keys, one Settings screen. A second
        // way of choosing a driver is a second thing to keep in step.
        return $this->provider ??= SoundSuggester::driver();
    }

    /**
     * @return array{options: array<int, array{title: string, why: string}>, provider: string, model: string}
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
            'options' => $this->clean($answer['options'] ?? [], $title),
            'provider' => $this->provider()->name(),
            'model' => $this->provider()->model(),
        ];
    }

    /* ═══════════════════════════ The prompt ═══════════════════════════ */

    private function system(): string
    {
        $count = self::COUNT;
        $max = self::MAX_LENGTH;

        return <<<PROMPT
        You suggest headline alternatives for an article on dbelo, a sound
        effects library. You are given the author's current title and the
        opening of their draft. You have read the draft; the suggestions must
        be about what it actually says.

        HARD RULES, in order of importance:

        1. NEVER CHANGE THE SUBJECT. The article is about what it is about.
           Do not add a number the draft does not contain, do not promise a
           comparison it does not make, do not widen "recording rain" into
           "recording weather". A title that the article fails to deliver is
           worse than a dull one: the reader leaves, and that leaving is
           measured.
        2. Give exactly {$count} options, and make them DIFFERENT ANGLES, not
           {$count} rewordings of the same sentence. One may lead with the
           search term, one may lead with the problem the reader has, one may
           lead with the outcome. If you cannot find {$count} genuinely
           different angles, repeat nothing — give fewer.
        3. At most {$max} characters each. Front-load the words that matter:
           a search result cuts the end, so a title whose subject arrives late
           can lose it entirely.
        4. WRITE IN THE SAME LANGUAGE AS THE DRAFT. If the draft is in
           Spanish, every title and every reason is in Spanish. Do not
           translate the author's work into English.
        5. Sentence case, no full stop at the end, no surrounding quotes, no
           emoji. Do not append the site name.

        BANNED, because every content farm uses them and they promise what an
        article cannot pay:
        "The ultimate guide", "Everything you need to know", "You won't
        believe", "in 2026" unless the draft is genuinely about a particular
        year, "Top 10" unless the draft genuinely lists ten things.

        THE REASON:

        6. One short line per option saying what that angle does — "leads with
           the term somebody would search", "names the problem before the
           solution", "shorter, so nothing is cut in results". It is written
           for the author, so it explains the CHOICE, not the title. Never
           describe the article back to them and never praise the option.
        PROMPT;
    }

    private function facts(string $title, string $excerpt, string $body, string $type): string
    {
        $lines = [
            'Kind: '.($type === 'page' ? 'a static page on the site' : 'a blog article'),
            'Current title: '.(trim($title) !== '' ? $title : '(the author has not written one yet)'),
        ];

        if (trim($excerpt) !== '') {
            $lines[] = 'Author\'s summary: '.trim($excerpt);
        }

        $draft = trim($body);

        if ($draft !== '') {
            // Markdown goes across as markdown. Stripping it would take the
            // headings with it, and the headings are the outline — the single
            // most useful signal about what the piece covers.
            $lines[] = "Opening of the draft:\n".Str::limit($draft, self::BODY_CHARS, '…');
        } else {
            $lines[] = 'The draft is still empty, so work from the title alone and stay close to it.';
        }

        return implode("\n\n", $lines);
    }

    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'options' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'why' => ['type' => 'string'],
                        ],
                        'required' => ['title', 'why'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['options'],
            'additionalProperties' => false,
        ];
    }

    /* ═══════════════════════ Trusting nothing ═══════════════════════ */

    /**
     * ── WHY THE ANSWER IS CHECKED AT ALL ─────────────────────────────────
     *
     * Because a schema is a request, not a guarantee. The model is asked for
     * three objects with two string fields and it will usually oblige — and
     * the one time it wraps a title in quotes, or returns an empty string, or
     * hands back the author's own title as a fresh idea, the panel would show
     * it as a suggestion worth considering.
     *
     * @param  mixed  $options
     * @return array<int, array{title: string, why: string}>
     */
    private function clean($options, string $current): array
    {
        if (! is_array($options)) {
            return [];
        }

        $seen = [];
        $out = [];

        foreach ($options as $option) {
            if (! is_array($option)) {
                continue;
            }

            $title = $this->tidy($option['title'] ?? null);
            $why = $this->tidy($option['why'] ?? null);

            if ($title === '' || mb_strlen($title) < 8) {
                continue;
            }

            // An option identical to what is already in the box is not an
            // option. Compared case- and punctuation-insensitively, because
            // the same title with a different capital is the same title.
            $key = Str::lower(preg_replace('/[^\p{L}\p{N}]+/u', '', $title));

            if ($key === '' || isset($seen[$key]) || $key === Str::lower(preg_replace('/[^\p{L}\p{N}]+/u', '', $current))) {
                continue;
            }

            $seen[$key] = true;

            $out[] = [
                // Cut on a word, never mid-word: a suggestion that arrives
                // already broken reads as a fault in the tool.
                'title' => Str::limit($title, self::MAX_LENGTH, ''),
                'why' => $why !== '' ? Str::limit($why, 120, '…') : '',
            ];

            if (count($out) === self::COUNT) {
                break;
            }
        }

        return $out;
    }

    private function tidy($value): string
    {
        if (! is_string($value)) {
            return '';
        }

        $value = trim(preg_replace('/\s+/u', ' ', $value));

        // Models like to hand back a title wearing quotation marks, straight
        // or curly, and a trailing full stop a headline never wants.
        $value = trim($value, " \t\n\r\0\x0B\"'“”‘’");

        return rtrim($value, '.');
    }

    /** Is the feature usable at all right now? The screen asks before drawing. */
    public static function ready(): bool
    {
        return Suggestions::ready();
    }
}
