<?php

namespace App\Jobs;

use App\Mail\CampaignMail;
use App\Models\Campaign;
use App\Models\CampaignSend;
use App\Models\Subscriber;
use App\Services\CampaignSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * One email. Deliberately the smallest possible unit of work: a bad address
 * fails alone, and a retry re-sends only that address.
 */
class SendCampaignEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public function __construct(
        public int $campaignId,
        public int $subscriberId,
    ) {}

    public function handle(CampaignSender $sender): void
    {
        $campaign = Campaign::find($this->campaignId);
        $subscriber = Subscriber::find($this->subscriberId);

        if (! $campaign || ! $subscriber) {
            return;
        }

        $send = CampaignSend::firstOrCreate([
            'campaign_id' => $campaign->id,
            'subscriber_id' => $subscriber->id,
        ]);

        if ($send->sent_at) {
            return;   // a previous attempt already delivered this one
        }

        // Checked again here, not only when the run was queued: a job can sit
        // in the queue for minutes, and someone who unsubscribed in between
        // must not receive it.
        if (! $subscriber->isSubscribed()) {
            $send->forceFill(['failed_at' => now(), 'error' => 'unsubscribed before sending'])->save();
            $sender->finishIfDone($campaign);

            return;
        }

        Mail::to($subscriber->email, $subscriber->name)
            ->send(new CampaignMail($campaign, $subscriber));

        $send->forceFill(['sent_at' => now(), 'failed_at' => null, 'error' => null])->save();

        $subscriber->forceFill(['last_sent_at' => now()])->save();
        $campaign->increment('sent_count');

        $sender->finishIfDone($campaign);
    }

    public function failed(?Throwable $e): void
    {
        CampaignSend::where('campaign_id', $this->campaignId)
            ->where('subscriber_id', $this->subscriberId)
            ->update([
                'failed_at' => now(),
                'error' => substr((string) $e?->getMessage(), 0, 250),
            ]);

        if ($campaign = Campaign::find($this->campaignId)) {
            $campaign->increment('failed_count');
            app(CampaignSender::class)->finishIfDone($campaign);
        }
    }
}
