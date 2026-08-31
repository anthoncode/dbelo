<?php

namespace App\Console\Commands;

use App\Models\Campaign;
use App\Services\CampaignSender;
use App\Services\DigestBuilder;
use Illuminate\Console\Command;

/**
 * Runs every hour and does nothing almost every time.
 *
 * Checking the schedule here rather than in the cron expression means the
 * day and time are editable from the panel — changing when the digest goes
 * out should not require touching a file.
 */
class SendDigestCommand extends Command
{
    protected $signature = 'newsletter:digest {--force : Send regardless of the schedule and the content check}';

    protected $description = 'Send the weekly digest if this is its hour and there is something worth sending';

    public function handle(DigestBuilder $builder, CampaignSender $sender): int
    {
        $digest = Campaign::digest();

        if (! $digest->is_active && ! $this->option('force')) {
            $this->line('Digest is off.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->isDue($digest)) {
            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $builder->worthSending($digest)) {
            $this->warn('Nothing worth sending this week — skipped.');

            return self::SUCCESS;
        }

        // A fresh row each week, so every send keeps its own delivery record
        // and the unique index still protects against a double run.
        $issue = $digest->replicate(['is_active', 'schedule_day', 'schedule_time']);
        $issue->title = 'Digest · '.now()->format('j M Y');
        $issue->subject = $builder->subject($digest);
        $issue->status = Campaign::STATUS_DRAFT;
        $issue->save();

        $queued = $sender->dispatch($issue);

        $this->info("Digest queued to {$queued} people.");

        return self::SUCCESS;
    }

    protected function isDue(Campaign $digest): bool
    {
        $day = (int) ($digest->schedule_day ?? 4);
        $hour = (int) substr((string) $digest->schedule_time, 0, 2);

        if (now()->dayOfWeek !== $day || now()->hour !== $hour) {
            return false;
        }

        // Belt and braces: if the scheduler fires twice in the same hour, the
        // second run finds this week's issue already made and stops.
        return ! Campaign::where('type', Campaign::TYPE_DIGEST)
            ->where('id', '!=', $digest->id)
            ->where('created_at', '>=', now()->subDays(3))
            ->exists();
    }
}
