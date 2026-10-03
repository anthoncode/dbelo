<?php

namespace App\Services\AI;

use App\Models\Category;
use App\Models\Sound;
use App\Support\AutoTags;
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
     * How long the description may be.
     *
     * ── WHY THESE NUMBERS GREW FROM 120–150 TO 200–320 ───────────────────
     *
     * The old pair sized this text for a SEARCH RESULT, and the text is not
     * a search result — it is the paragraph a visitor reads on the page.
     * One string was doing two jobs and was measured for the smaller one, so
     * the reader got the short end of a trade nobody had decided to make.
     *
     * They are now separate. This is the paragraph; the snippet Google gets
     * is derived from it by Serp::snippet(), cut at a sentence that fits the
     * width of a result line. Two or three sentences fit comfortably under
     * the detail block's own clamp of 445, so the page shows the whole thing
     * without its "See more" ever appearing — the button stays for the
     * descriptions a person writes by hand and makes longer.
     *
     * It also feeds the 63–70% of the time Google writes its own snippet
     * anyway: it builds that from page content, and 135 characters is almost
     * nothing to build from.
     */
    private const DESCRIPTION_MIN = 200;

    private const DESCRIPTION_MAX = 320;

    /**
     * Below this, the answer is thrown away instead of stored.
     *
     * ── WHY THE FLOOR MOVED FROM 40 TO 110 ───────────────────────────────
     *
     * DESCRIPTION_MIN is what the PROMPT asks for. It was never enforced:
     * cleanDescription() only rejected answers under forty characters, so a
     * model that came back with ninety-five was believed. The ceiling was
     * real and the floor was a wish, and a limit applied on one side only
     * produces exactly what that does — never too long, frequently short.
     *
     * A ninety-five character description fills two thirds of the result
     * Google will print and leaves the rest empty. It is also the version
     * nobody ever goes back to improve, because it looks finished.
     *
     * Kept at a tenth under DESCRIPTION_MIN when that pair moved to 200–320,
     * so the model still has a little room under what it was asked for and a
     * good paragraph is not binned over a handful of characters.
     *
     * A rejected description costs nothing else: the tags from the same
     * answer are still written, the sound simply shows no suggestion to
     * accept, and the per-sound "ask again" button on the moderation screen
     * is right there.
     */
    private const DESCRIPTION_FLOOR = 180;

    /*
     * How many tags a MODEL may propose: AutoTags::MAX_AUTOMATIC.
     *
     * It was 14 here, which is where "some sounds have far too many" came
     * from. The number is not repeated in this class on purpose — the same
     * ceiling has to survive the top-up that happens after this answer
     * comes back, and a second copy is a second thing to forget.
     */

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

        THE DESCRIPTION, which is the paragraph on the page people read:

        6. WRITE EXACTLY THREE SENTENCES. Each one has a different job, and a
           sentence that does another sentence's job is a wasted sentence:

             FIRST  — WHAT the sound is and how it sounds. Its character:
                      sharp, dull, warm, tense, hollow, bright.
             SECOND — WHERE it gets used. The kind of scene, project or
                      moment an editor would reach for it in.
             THIRD  — ONE PRACTICAL FACT about the recording itself, taken
                      from what you were given: whether it loops cleanly,
                      whether it is a short hit or a long bed, whether it
                      sits close or far, whether it is clean or has room on
                      it. Not a repetition of the first sentence in other
                      words.

           Three sentences doing three jobs land between {$min} and {$max}
           characters on their own. Do not count characters — count
           sentences, and check there are three before you answer.

        7. A WORKED EXAMPLE. Given "Wooden Door Close Interior.wav", 2
           seconds, not loopable:

           GOOD: "Wood meets frame in one dull, solid knock, with a short
           tail of air behind it. It fits interiors where somebody leaves a
           room — a kitchen, an office, the end of an argument. Close-miked
           and dry, so it sits under dialogue without fighting it."

           Note what the good one does NOT start with: "A ", "This ", "The
           sound of". Rule 9 is not decoration.

           BAD: "This is a high quality wooden door close sound effect.
           Perfect for your video projects. Crystal clear 48kHz audio."

           The bad one is banned three times over: it names the sound again
           instead of describing it, it uses the phrases in rule 10, and its
           third sentence is a spec sheet. The good one could only have been
           written about this file.

        7b. Everything in the third sentence must come from the facts you
           were given or from what the filename plainly implies. "Close-
           miked and dry" is fair for a clean interior recording; "recorded
           in a 19th century farmhouse" is not. When you have nothing
           practical to say, say something true about its shape — a single
           hit, a slow swell, an even bed — rather than inventing.
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

           NEVER WRITE A NUMBER OR A FORMAT. No "2 seconds", no "48 kHz", no
           "MP3", no "stereo" as a spec. The page prints the real figures in
           a table beside this text, so a number here is either a duplicate
           or — the day the file is replaced — a lie that nobody thinks to
           check.

           The QUALITY those numbers describe is yours to use, and rule 6's
           third sentence depends on it: "a short hit", "a long even bed",
           "loops without a seam" say what an editor needs and cannot go
           stale.

        11. Plain and useful. You are not selling anything — whoever reads
           this page has already found the sound. Describe it, place it, and
           stop. No adjective that could be applied to any file in the
           library has earned its place in this one.

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

        /*
         * The duration again, as a SHAPE rather than a number.
         *
         * Rule 6 asks the third sentence for a practical fact and rule 10
         * forbids writing the figure, which leaves the model to turn
         * "2 seconds" into a word on its own. It is bad at that boundary —
         * four seconds reads as "short" to one answer and "sustained" to
         * the next, so the same catalogue describes itself inconsistently.
         *
         * Deciding it here makes it one rule instead of a judgement call
         * repeated a thousand times, and the thresholds are editable by
         * somebody who can see all the sounds at once, which the model
         * never can.
         */
        if ($seconds > 0) {
            $lines[] = 'Shape: '.match (true) {
                $seconds <= 2 => 'a single short hit',
                $seconds <= 6 => 'a short sound with a tail',
                $seconds <= 20 => 'a sustained sound',
                $seconds <= 90 => 'a long bed',
                default => 'a very long bed, for looping under a whole scene',
            };
        }

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
    private function cleanTags($tags, int $limit = AutoTags::MAX_AUTOMATIC): array
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

        /*
         * Too short is a non-answer and is dropped, so the review screen
         * shows nothing rather than something useless.
         *
         * Enforced against DESCRIPTION_FLOOR, not against a number written
         * here: the prompt and the filter have to agree, and they only stay
         * agreed if there is one place to change.
         */
        if (mb_strlen($clean) < self::DESCRIPTION_FLOOR) {
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

        /*
         * The sentence cut is taken ONLY when what survives is still a
         * description.
         *
         * ── THIS IS WHAT MADE EVERY DESCRIPTION COME OUT AT ~110 ─────────
         *
         * Rule 6 of the prompt asks for "one sentence, or two short ones",
         * and the model obliges: a 190-character answer whose first sentence
         * ends around 110 and whose second runs past 150. The only ". "
         * inside the 150-character window is therefore the one at 110 — and
         * the old guard, `$lastStop > 60`, accepted it without ever asking
         * how much was left.
         *
         * So two thirds of a perfectly good answer went in the bin, every
         * time, and the result read as the model undershooting when it was
         * this function doing the cutting. The ceiling was enforced twice
         * and the floor not at all — the same asymmetry the FLOOR constant
         * above was written to fix, surviving one layer further down.
         *
         * Measured against DESCRIPTION_FLOOR rather than a number of its
         * own: it is already the answer to "long enough to be worth
         * keeping", and a second opinion on that question is a second thing
         * to forget. When the surviving sentence does not reach it, the
         * word-boundary trim below keeps the full 150 instead — a little
         * less elegant than ending on a full stop, and a description rather
         * than a fragment.
         */
        if ($lastStop + 1 >= self::DESCRIPTION_FLOOR) {
            return rtrim(mb_substr($window, 0, $lastStop + 1));
        }

        return rtrim(Str::limit($clean, self::DESCRIPTION_MAX, '', preserveWords: true), " ,;:-–—");
    }
}
