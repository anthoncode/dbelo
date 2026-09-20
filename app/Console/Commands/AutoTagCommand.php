<?php

namespace App\Console\Commands;

use App\Models\Sound;
use App\Support\AutoTags;
use Illuminate\Console\Command;

/**
 * Give every under-tagged sound tags taken from its title and its category.
 *
 * ── WHY THIS IS SEPARATE FROM sounds:suggest ─────────────────────────────
 *
 * It spends nothing. No API key, no quota, no network, no queue — it reads
 * columns that are already in the database and writes rows. That makes it the
 * thing to run FIRST over an imported catalogue, and the thing that still
 * works on the day a provider is down or a free tier is exhausted.
 *
 * Folding it into sounds:suggest as a flag would have tied a free operation
 * to a paid one, and then "just tag everything" would have needed a key.
 *
 * Nothing here is ever removed. A sound that already has three tags is
 * skipped entirely, and one that does not is topped up around whatever it has.
 */
class AutoTagCommand extends Command
{
    protected $signature = 'sounds:autotag
        {--limit=500 : How many sounds to look at.}
        {--min=3 : Sounds with at least this many tags are left alone.}
        {--status= : Only sounds in this status. Default: all but rejected.}
        {--dry : Print what would be added and write nothing.}';

    protected $description = 'Tag sounds from their title and category — no AI, no API key, no cost';

    public function handle(): int
    {
        $minimum = max(1, (int) $this->option('min'));

        $sounds = Sound::query()
            ->with(['tags', 'category.parent', 'user'])
            ->when($this->option('status'), fn ($q, $status) => $q->where('status', $status))
            ->when(! $this->option('status'), fn ($q) => $q->where('status', '!=', Sound::STATUS_REJECTED))
            // Counting in SQL rather than loading everything and filtering in
            // PHP: on a catalogue this is one query instead of one per sound.
            ->has('tags', '<', $minimum)
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        if ($sounds->isEmpty()) {
            $this->info("Nothing to do — every sound already has {$minimum} tags or more.");

            return self::SUCCESS;
        }

        $this->line('');
        $this->line('  '.$sounds->count().' sound'.($sounds->count() === 1 ? '' : 's').' under '.$minimum.' tags'
            .($this->option('dry') ? '  (dry run, nothing will be written)' : ''));
        $this->line('');

        $touched = 0;
        $added = 0;

        foreach ($sounds as $sound) {
            $names = AutoTags::forSound($sound);

            if ($names === []) {
                $this->line("  <fg=yellow>none</>   {$sound->id}  {$sound->title}");
                $this->line('          nothing usable in the title or the category');

                continue;
            }

            if ($this->option('dry')) {
                $this->line("  <fg=blue>would</>  {$sound->id}  {$sound->title}");
                $this->line('          '.implode(', ', $names));

                $touched++;

                continue;
            }

            $new = AutoTags::attach($sound, $names);

            $this->line("  <fg=green>ok</>     {$sound->id}  {$sound->title}");
            $this->line('          +'.$new.'  '.implode(', ', $names));

            $touched++;
            $added += $new;
        }

        $this->line('');

        $this->info($this->option('dry')
            ? "{$touched} sounds would be tagged. Run again without --dry to write them."
            : "{$touched} sounds tagged, {$added} tags added.");

        if (! $this->option('dry')) {
            $this->line('  Published sounds re-index in Meilisearch on save, so the new tags are searchable now.');
        }

        return self::SUCCESS;
    }
}
