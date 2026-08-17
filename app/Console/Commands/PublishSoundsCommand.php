<?php

namespace App\Console\Commands;

use App\Models\Sound;
use Illuminate\Console\Command;

/**
 * Approve sounds waiting for review. A temporary stand-in for the
 * moderation screen.
 */
class PublishSoundsCommand extends Command
{
    protected $signature = 'sounds:publish
                            {slug? : Slug of a single sound. Omit to publish every pending sound}';

    protected $description = 'Publish pending sounds so they appear in the catalogue';

    public function handle(): int
    {
        $query = Sound::query()->where('status', 'pending');

        if ($slug = $this->argument('slug')) {
            $query->where('slug', $slug);
        }

        $sounds = $query->get();

        if ($sounds->isEmpty()) {
            $this->warn('No pending sounds found.');

            return self::SUCCESS;
        }

        foreach ($sounds as $sound) {
            $sound->update([
                'status' => 'published',
                'published_at' => $sound->published_at ?? now(),
                'reviewed_by' => $sound->reviewed_by,
                'reviewed_at' => now(),
            ]);

            $this->line("  <info>✓</info> {$sound->title}");
        }

        $this->info("Published {$sounds->count()} sound(s).");

        return self::SUCCESS;
    }
}
