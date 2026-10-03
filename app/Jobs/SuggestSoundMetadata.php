<?php

namespace App\Jobs;

use App\Models\Sound;
use App\Services\AI\AiException;
use App\Services\AI\SoundSuggester;
use App\Support\AutoTags;
use App\Support\Suggestions;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ask a model what this sound should be tagged, filed under and described as.
 *
 * ── WHY THIS IS A JOB AND NOT PART OF THE UPLOAD ─────────────────────────
 *
 * Fifty files land in an afternoon and the person who uploaded them walks
 * away. Nothing here may hold a web request open, and nothing here may fail
 * the upload: a sound whose suggestions did not arrive is a completely
 * normal sound that simply has none.
 *
 * ── RATE LIMITING IS NOT AN ERROR ────────────────────────────────────────
 *
 * Both providers' free tiers will refuse a large backfill partway through,
 * and that refusal is the expected behaviour of a free tier, not a fault.
 * A 429 releases the job back to the queue with a delay instead of counting
 * an attempt against it. Treating it as a failure would fill failed_jobs
 * with work that was never broken — and that table has already misled us
 * once on this project.
 *
 * Everything else gets three tries and then gives up quietly.
 */
class SuggestSoundMetadata implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 90;

    /** 1m, 5m, 15m: slow enough that a per-minute quota has recovered. */
    public array $backoff = [60, 300, 900];

    public function __construct(
        public int $soundId,
        /** null uses the configured driver; a name forces one, for comparisons. */
        public ?string $provider = null,
    ) {}

    public function handle(SoundSuggester $suggester): void
    {
        $sound = Sound::with('category')->find($this->soundId);

        if (! $sound) {
            return;
        }

        /*
         * A key can be removed between this job being queued and a worker
         * picking it up. Leaving quietly rather than throwing, because "there
         * is no key" is a configuration state and not a fault — and a fault
         * here would sit in failed_jobs looking like something broke.
         */
        if (! Suggestions::ready($this->provider)) {
            Log::info('Sound suggestions skipped: no API key', ['sound' => $sound->id]);

            return;
        }

        $driver = $this->provider
            ? new SoundSuggester(SoundSuggester::driver($this->provider))
            : $suggester;

        try {
            $suggestions = $driver->for($sound);
        } catch (AiException $e) {
            if ($e->retryable) {
                /*
                 * Back to the queue without burning an attempt. release()
                 * rather than throwing, so a quota that resets tomorrow does
                 * not turn fifty perfectly good sounds into fifty failures
                 * tonight.
                 */
                $this->release($this->backoff[0]);

                return;
            }

            Log::warning('Sound suggestions unavailable', [
                'sound' => $sound->id,
                'provider' => $e->provider,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        } catch (Throwable $e) {
            Log::warning('Sound suggestions failed', ['sound' => $sound->id, 'error' => $e->getMessage()]);

            throw $e;
        }

        /*
         * An answer with nothing usable in it is not recorded.
         *
         * Stamping ai_suggested_at on an empty result would mark the sound as
         * done and hide it from every re-run — the failure would become
         * permanent and silent, which is the shape of bug that costs a week
         * to notice.
         */
        if ($suggestions['tags'] === [] && blank($suggestions['meta_description'])) {
            Log::info('Sound suggestions came back empty', ['sound' => $sound->id]);

            return;
        }

        $sound->forceFill([
            'ai_suggestions' => $suggestions,
            'ai_suggested_at' => now(),
            'ai_provider' => $suggestions['provider'],

            /*
             * WRITTEN STRAIGHT IN, like the tags and unlike the description.
             *
             * These never appear on a page. Nobody reads them, nobody is
             * misled by them, and the worst a wrong one can do is match a
             * search it should not have — which costs a visitor one glance,
             * where a missing one costs the whole visit.
             *
             * The column is replaced rather than merged: a re-run means the
             * model was asked again, usually because the first answer was not
             * good enough, and keeping both would quietly accumulate every
             * version of every mistake.
             */
            'search_terms' => $suggestions['search_terms'] ?? [],
        ])->save();

        /*
         * ── TAGS ARE WRITTEN. THE DESCRIPTION IS NOT. ────────────────────
         *
         * They are not the same kind of claim, so they do not get the same
         * treatment.
         *
         * A wrong tag is noise in the search box: cheap to add, cheap to
         * remove, and an extra one rarely hurts anybody. Meanwhile a sound
         * with no tags is a sound the search barely finds, and waiting for a
         * person to approve them means the catalogue stays unfindable for as
         * long as that queue is unread.
         *
         * A wrong description is a false sentence on the public page and in
         * the Google result, written by something that never heard the audio.
         * That one waits for a person, every time.
         *
         * Never sync() — syncWithoutDetaching inside attach(). Tags somebody
         * typed by hand must survive this.
         */
        $names = $suggestions['tags'];

        if (count($names) < AutoTags::MINIMUM) {
            // A thin answer is topped up from the title and the category
            // rather than left short. Those words are already in the data, so
            // nothing here can be invented.
            $names = array_merge($names, AutoTags::forSound($sound));
        }

        /*
         * The ceiling applies to the TOTAL, not just to the model's share.
         *
         * Two tags from a thin answer plus six from the filename is eight,
         * and the cap on the answer alone would never have seen them. This
         * is the line that makes "at most five automatic tags" true rather
         * than intended.
         */
        $names = array_slice($names, 0, AutoTags::MAX_AUTOMATIC);

        $added = AutoTags::attach($sound, $names);

        Log::info('Sound suggestions stored', [
            'sound' => $sound->id,
            'provider' => $suggestions['provider'],
            'tags_added' => $added,
        ]);
    }
}
