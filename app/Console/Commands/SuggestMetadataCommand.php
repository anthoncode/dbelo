<?php

namespace App\Console\Commands;

use App\Jobs\SuggestSoundMetadata;
use App\Models\Sound;
use App\Services\AI\SoundSuggester;
use App\Support\Suggestions;
use Illuminate\Console\Command;
use Throwable;

/**
 * Ask a model for tags, a category and a description for sounds that have
 * none — the catch-up pass for everything imported before the suggester
 * existed.
 *
 * ── WHY THIS IS A COMMAND AND NOT A BUTTON ───────────────────────────────
 *
 * A hundred sounds is a hundred HTTP calls and several minutes of a free
 * tier's patience. A button that starts that from a web request is a button
 * whose result nobody sees: the page times out, the work either continues
 * invisibly or dies half way, and there is no way to tell which. A command
 * prints a line per sound and can be stopped with ctrl-c.
 *
 * It only QUEUES, so the rate limiting, the backoff and the release-instead-
 * of-fail behaviour in SuggestSoundMetadata all apply. --sync exists for
 * looking at one answer immediately.
 *
 * Nothing this writes is ever published. The suggestions land on the sound
 * and wait in Admin → In review until a person accepts them.
 */
class SuggestMetadataCommand extends Command
{
    protected $signature = 'sounds:suggest
        {--limit=50 : How many to queue. The free tiers are generous but not infinite.}
        {--status= : Only sounds in this status (pending, published, draft…). Default: all but rejected.}
        {--force : Include sounds that already have a suggestion, replacing it.}
        {--provider= : gemini or openai, overriding the configured one. For comparing the two.}
        {--sync : Run in this process instead of queueing, so the answer is printed here.}';

    protected $description = 'Queue AI metadata suggestions for sounds that do not have one yet';

    public function handle(): int
    {
        $provider = $this->option('provider') ?: Suggestions::provider();

        if (! array_key_exists($provider, Suggestions::PROVIDERS)) {
            $this->error("Unknown provider '{$provider}'. Use gemini or openai.");

            return self::FAILURE;
        }

        if (! Suggestions::ready($provider)) {
            $this->error(Suggestions::PROVIDERS[$provider].' has no API key.');
            $this->line('  Set one in Admin → Settings → Suggestions, or in .env.');

            return self::FAILURE;
        }

        $sounds = $this->targets();

        if ($sounds->isEmpty()) {
            $this->info('Nothing to do — every sound already has a suggestion.');

            return self::SUCCESS;
        }

        $this->line('');
        $this->line("  Provider  {$provider} · ".Suggestions::model($provider));
        $this->line('  Sounds    '.$sounds->count().($this->option('sync') ? ' (running here)' : ' (queued)'));
        $this->line('');

        // A backfill costs money or quota, and --force overwrites answers that
        // were possibly already reviewed. Ask once, unless this is running
        // unattended.
        if ($this->option('force') && ! $this->option('no-interaction')
            && ! $this->confirm('--force replaces suggestions that already exist. Continue?')) {
            return self::SUCCESS;
        }

        $done = 0;

        foreach ($sounds as $sound) {
            if ($this->option('sync')) {
                $this->runNow($sound, $provider);
            } else {
                SuggestSoundMetadata::dispatch($sound->id, $this->option('provider') ?: null);
                $this->line("  queued   {$sound->id}  {$sound->title}");
            }

            $done++;
        }

        $this->line('');

        $this->info($this->option('sync')
            ? "{$done} done."
            : "{$done} queued. They appear in Admin → In review as the worker gets through them.");

        if (! $this->option('sync')) {
            $this->line('  The queue worker has to be running: ./dev.sh');
        }

        return self::SUCCESS;
    }

    /**
     * Which sounds get asked about.
     *
     * Rejected ones are excluded always: they are not coming back, and paying
     * a model to describe them is paying for nothing.
     */
    private function targets()
    {
        return Sound::query()
            ->with('category')
            ->when($this->option('status'), fn ($q, $status) => $q->where('status', $status))
            ->when(! $this->option('status'), fn ($q) => $q->where('status', '!=', Sound::STATUS_REJECTED))
            ->when(! $this->option('force'), fn ($q) => $q->whereNull('ai_suggested_at'))
            // Oldest first, so a run that is cut short leaves a predictable
            // boundary instead of a random half.
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();
    }

    private function runNow(Sound $sound, string $provider): void
    {
        try {
            $answer = (new SoundSuggester(SoundSuggester::driver($provider)))->for($sound);
        } catch (Throwable $e) {
            $this->line("  <fg=red>failed</>   {$sound->id}  {$sound->title}");
            $this->line('            '.$e->getMessage());

            return;
        }

        if ($answer['tags'] === [] && blank($answer['meta_description'])) {
            $this->line("  <fg=yellow>empty</>    {$sound->id}  {$sound->title}");

            return;
        }

        $sound->forceFill([
            'ai_suggestions' => $answer,
            'ai_suggested_at' => now(),
            'ai_provider' => $answer['provider'],
            'search_terms' => $answer['search_terms'] ?? [],
        ])->save();

        $this->line("  <fg=green>ok</>       {$sound->id}  {$sound->title}");
        $this->line('            '.implode(', ', array_slice($answer['tags'], 0, 8)));
        $this->line('         es '.implode(', ', array_slice($answer['search_terms'] ?? [], 0, 8)));
        $this->line('            '.($answer['meta_description'] ?: '—'));
    }
}
