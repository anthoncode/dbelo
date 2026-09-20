<?php

namespace App\Services\AI;

use App\Models\Category;
use App\Models\Sound;
use App\Support\Suggestions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Turns what dbelo already knows about a sound into tags, a category and a
 * meta description — as SUGGESTIONS, never as writes.
 *
 * ── THE ONE RULE THIS CLASS EXISTS TO ENFORCE ────────────────────────────
 *
 * The model has not heard the audio. It is reading a filename.
 *
 * Left unconstrained it will happily write "a roaring crowd of fifty
 * thousand fills the stadium" about eight seconds of three people shouting,
 * and that sentence then lives in the catalogue as if it were true. Wrong
 * descriptions are worse than missing ones: they are what a search engine
 * penalises and what makes somebody distrust every other entry.
 *
 * So the prompt forbids new facts, the duration is supplied as hard data so
 * scale cannot be invented, and the category must be chosen from a closed
 * list that is validated again after the answer comes back. A model cannot
 * be trusted to stay inside a set just because it was asked to.
 *
 * ── WHY THE FILENAME PARSER RUNS FIRST ───────────────────────────────────
 *
 * App\Support\FilenameMeta already returns a category and a confidence from
 * 0 to 100 at no cost and no latency. Sending every sound to a model would
 * be paying for an answer that is already on the disk. The confidence is the
 * switch: above the threshold the rules win and nothing is spent.
 */
class SoundSuggester
{
    /**
     * Below this, the filename did not tell us enough and the model is worth
     * asking about the category. Tags are asked for either way — that is the
     * part a filename genuinely cannot provide.
     */
    public const CATEGORY_CONFIDENCE_FLOOR = 60;

    /**
     * How long a description may be.
     *
     * 150 rather than Google's 160: the snippet is cut at a width, not at a
     * character count, and a sentence that ends before the limit reads as
     * finished where one that runs to it reads as truncated. The floor is
     * there because forty characters is a label, not a description — but it
     * is low enough that a genuinely simple sound is allowed a short answer
     * instead of being padded to reach a number.
     */
    private const DESCRIPTION_MIN = 120;

    private const DESCRIPTION_MAX = 150;

    public function __construct(private ?AiProvider $provider = null) {}

    public function provider(): AiProvider
    {
        return $this->provider ??= self::driver();
    }

    /**
     * The configured driver, or a named one.
     *
     * Named is how the two get compared over the same twenty sounds without
     * changing any configuration: pass 'openai' once, pass 'gemini' once,
     * read the results next to each other.
     */
    public static function driver(?string $name = null): AiProvider
    {
        $name = strtolower(trim($name ?? Suggestions::provider()));

        return match ($name) {
            'openai' => new OpenAiProvider,
            default => new GeminiProvider,
        };
    }

    /**
     * @return array{tags: array<int,string>, search_terms: array<int,string>, category: ?string, meta_description: ?string, provider: string, model: string}
     *
     * @throws AiException
     */
    public function for(Sound $sound): array
    {
        $categories = $this->categoryList();

        $answer = $this->provider()->complete(
            $this->system($categories),
            $this->facts($sound),
            $this->schema(),
        );

        return [
            'tags' => $this->cleanTags($answer['tags'] ?? []),
            // Cleaned by the same rules but kept apart: these are never
            // rendered, so they are allowed to be more numerous and uglier.
            'search_terms' => $this->cleanTags($answer['search_terms_es'] ?? [], 20),
            'category' => $this->cleanCategory($answer['category'] ?? null, $categories),
            'meta_description' => $this->cleanDescription($answer['meta_description'] ?? null),
            'provider' => $this->provider()->name(),
            'model' => $this->provider()->model(),
        ];
    }

    /* ═══════════════════════════ The prompt ═══════════════════════════ */

    private function system(array $categories): string
    {
        $slugs = implode(', ', array_keys($categories));
        $max = self::DESCRIPTION_MAX;
        $min = self::DESCRIPTION_MIN;

        return <<<PROMPT
        You label sound effects for a stock audio library. You are given the
        ORIGINAL FILENAME of a recording and its duration. You have not heard
        the audio.

        HARD RULES, in order of importance:

        1. Never state a fact the filename does not already claim. Do not
           invent a location, a crowd size, a weather condition, an
           instrument, a story or a recording technique. If the filename says
           "door close", the sound is a door closing — not "a heavy oak door
           in an abandoned mansion".
        2. The duration given is the truth. A four second file cannot contain
           a long build-up, and a ten minute one is not a quick hit.
        3. Choose the category from this list ONLY, by its slug: {$slugs}
           If none genuinely fits, return an empty string.
        4. Tags are for search. Give the words a video editor would type,
           including obvious synonyms and the broader family the sound belongs
           to. Lower case, single or two words each, no punctuation, no
           duplicates, no brand or person names, 8 to 14 of them.
        5. search_terms_es is the SAME IDEA IN SPANISH, and it is the only
           reason a Spanish speaker finds this catalogue at all. Somebody who
           types "truenos" today gets nothing and concludes the library has no
           thunder.
           Give 6 to 12 Spanish words or short phrases a Spanish-speaking
           video editor would actually type: the direct translations first,
           then the regional variants and the near-misses. For a thunder clap:
           trueno, truenos, tormenta, rayo, relampago, temporal.
           Write them WITHOUT accents as well as with them where it differs —
           people type "relampago" far more often than "relámpago". Lower
           case, no punctuation, no duplicates. These are never displayed, so
           an ugly but likely word beats an elegant but unlikely one.

        THE META DESCRIPTION, which is the part people read:

        6. Between {$min} and {$max} characters. Aim near the top of that
           range: Google gives a result about 990 pixels of width, and a
           description of 95 characters leaves a third of it empty — free
           space nobody else can use. Going past {$max} risks the cut. One
           sentence, or two short ones.
        7. It has to answer two things: WHAT the sound is, and WHAT SOMEBODY
           WOULD USE IT FOR — the kind of project, scene or moment it fits.
           A description that only names the sound again has said nothing the
           title did not.
        8. Write like a person, not a catalogue. You may name the character
           the sound HAS — tense, warm, gentle, urgent, playful, cold — when
           the filename already implies it. "Elegant logo" is elegant; say so.
           This is NOT permission to invent a scene, a place or a feeling the
           name does not support. The character of the sound is fair game;
           anything that happened around it is not.
        9. VARY THE OPENING. Two thousand descriptions that begin the same way
           are two thousand descriptions a search engine treats as one. Do not
           start with "A ", "This ", "The sound of" every time. Change the
           shape of the sentence between one sound and the next.
        10. Banned outright, because every stock library already overuses them
           and they say nothing: "perfect for", "ideal for", "high quality",
           "crystal clear", "professional", "royalty free", "this sound".
           Never mention the file format, the bitrate or the duration.

        Write the tags and the description in English. search_terms_es is the
        one field in Spanish.
        PROMPT;
    }

    private function facts(Sound $sound): string
    {
        $seconds = (int) round(($sound->duration_ms ?? 0) / 1000);

        /*
         * The title carries the filename's information.
         *
         * Files are stored under a UUID and the name they arrived with was
         * never kept, so for everything imported before now the title — as
         * FilenameMeta cleaned it — is the only trace of it left. The
         * migration alongside this class adds original_filename so future
         * imports keep the raw signal, which is richer than the tidied
         * title; this reads it when it is there and falls back when it is
         * not.
         */
        $lines = [
            'Filename: '.($sound->original_filename ?: $sound->title),
            'Current title: '.$sound->title,
            'Duration: '.($seconds > 0 ? $seconds.' seconds' : 'unknown'),
            'Type: '.($sound->type === 'music' ? 'music' : 'sound effect'),
        ];

        if ($sound->is_loopable) {
            $lines[] = 'Loops seamlessly: yes';
        }

        // What the filename parser already worked out, so the model refines
        // rather than starts over — and so a good rule-based guess is not
        // silently contradicted.
        if ($sound->category?->name) {
            $lines[] = 'Category guessed from the filename: '.$sound->category->name;
        }

        if (filled($sound->description)) {
            $lines[] = 'Existing description: '.Str::limit((string) $sound->description, 300);
        }

        return implode("\n", $lines);
    }

    /**
     * One schema, translated per provider by the drivers.
     *
     * additionalProperties:false and every key required, because OpenAI's
     * strict mode demands both — and a schema that is strict for one provider
     * and loose for the other would give two different answers to the same
     * comparison.
     */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                'search_terms_es' => ['type' => 'array', 'items' => ['type' => 'string']],
                'category' => ['type' => 'string'],
                'meta_description' => ['type' => 'string'],
            ],
            'required' => ['tags', 'search_terms_es', 'category', 'meta_description'],
            'additionalProperties' => false,
        ];
    }

    /* ═══════════════════════════ Trusting nothing ═══════════════════════════ */

    /** slug => name, cached: it changes about never and is read per sound. */
    private function categoryList(): array
    {
        return Cache::remember('ai.categories', now()->addHour(), function () {
            return Category::query()->orderBy('name')->pluck('name', 'slug')->all();
        });
    }

    /**
     * @param  mixed  $tags
     * @return array<int, string>
     */
    private function cleanTags($tags, int $limit = 14): array
    {
        if (! is_array($tags)) {
            return [];
        }

        return collect($tags)
            ->filter(fn ($tag) => is_string($tag))
            ->map(fn ($tag) => Str::lower(trim(preg_replace('/[^\p{L}\p{N}\s-]/u', '', $tag))))
            ->map(fn ($tag) => preg_replace('/\s+/', ' ', $tag))
            // One character is not a search term; forty is a sentence.
            ->filter(fn ($tag) => mb_strlen($tag) > 1 && mb_strlen($tag) <= 40)
            ->unique()
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * The category, only if it is really one of ours.
     *
     * Asked for in the prompt AND checked here. A model told to pick from a
     * list will occasionally return something adjacent and plausible that
     * does not exist, and a category_id that matches nothing is a sound that
     * disappears from every filter.
     */
    private function cleanCategory($category, array $categories): ?string
    {
        if (! is_string($category)) {
            return null;
        }

        $slug = Str::slug(trim($category));

        return $slug !== '' && array_key_exists($slug, $categories) ? $slug : null;
    }

    private function cleanDescription($description): ?string
    {
        if (! is_string($description)) {
            return null;
        }

        $clean = trim(preg_replace('/\s+/', ' ', $description));

        if ($clean === '') {
            return null;
        }

        // Too short is a non-answer and is dropped, so the review screen
        // shows nothing rather than something useless.
        if (mb_strlen($clean) < 40) {
            return null;
        }

        if (mb_strlen($clean) <= self::DESCRIPTION_MAX) {
            return $clean;
        }

        /*
         * Over the limit gets trimmed rather than thrown away, but WHERE it
         * is trimmed decides whether the result reads as written or as
         * broken.
         *
         * First choice is the last full stop that still fits: the sentence
         * ends where a person would have ended it. Only if there is no
         * sentence break in range does it fall back to a word boundary —
         * because a description cut mid-word does not look like an editorial
         * decision, it looks like a bug, and it is the first thing anybody
         * sees in a Google result.
         */
        $window = mb_substr($clean, 0, self::DESCRIPTION_MAX);

        // Written out rather than max(): mb_strrpos returns false when a
        // needle is absent, and max(false, 0) is a coin toss in PHP. A
        // comparison that is right by accident is a comparison that breaks
        // the day the data changes.
        $lastStop = -1;

        foreach (['. ', '! ', '? '] as $ending) {
            $at = mb_strrpos($window, $ending);

            if ($at !== false && $at > $lastStop) {
                $lastStop = $at;
            }
        }

        // Only when the sentence that survives is still worth reading.
        if ($lastStop > 60) {
            return rtrim(mb_substr($window, 0, $lastStop + 1));
        }

        return rtrim(Str::limit($clean, self::DESCRIPTION_MAX, '', preserveWords: true), " ,;:-–—");
    }
}
