<?php

namespace App\Services;

use App\Jobs\SendCampaignEmail;
use App\Models\Campaign;
use App\Models\CampaignSend;
use App\Models\Subscriber;
use Illuminate\Database\Eloquent\Builder;

/**
 * Turns a campaign into one queued job per recipient.
 *
 * One job each rather than one job for the whole list: a single address that
 * blows up takes down its own email instead of the other four hundred, and a
 * retry re-sends only that one.
 */
class CampaignSender
{
    public function recipients(Campaign $campaign): Builder
    {
        $query = Subscriber::query()->wanting($campaign->type);

        return match ($campaign->segment) {
            // Someone paying nine dollars a month should never be sent
            // "upgrade to Pro". The fastest way to lose a subscriber is to
            // show them you do not know they are one.
            'paying' => $query->whereHas('user.subscriptions', fn ($q) => $q->active()),

            'free' => $query->where(fn ($q) => $q
                ->whereNull('user_id')
                ->orWhereHas('user', fn ($u) => $u->whereDoesntHave('subscriptions', fn ($s) => $s->active()))),

            'contributors' => $query->whereHas('user', fn ($u) => $u->where('role', 'collaborator')),

            default => $query,
        };
    }

    public function count(Campaign $campaign): int
    {
        return $this->recipients($campaign)->count();
    }

    /**
     * Queue the whole run.
     *
     * The send row is written here, before the email exists, so the unique
     * index decides who is already handled. A double click on Send, or a
     * worker that restarts halfway, cannot produce a second copy.
     */
    public function dispatch(Campaign $campaign): int
    {
        $campaign->forceFill([
            'status' => Campaign::STATUS_SENDING,
            'started_at' => now(),
            'recipients_count' => $this->count($campaign),
            'sent_count' => 0,
            'failed_count' => 0,
        ])->save();

        $queued = 0;

        $this->recipients($campaign)->chunkById(200, function ($subscribers) use ($campaign, &$queued) {
            foreach ($subscribers as $subscriber) {
                $send = CampaignSend::firstOrCreate([
                    'campaign_id' => $campaign->id,
                    'subscriber_id' => $subscriber->id,
                ]);

                if ($send->sent_at) {
                    continue;   // already delivered in an earlier run
                }

                SendCampaignEmail::dispatch($campaign->id, $subscriber->id);
                $queued++;
            }
        });

        if ($queued === 0) {
            $campaign->forceFill(['status' => Campaign::STATUS_SENT, 'sent_at' => now()])->save();
        }

        return $queued;
    }

    /** Called by the last job to finish, so the screen stops saying "sending". */
    public function finishIfDone(Campaign $campaign): void
    {
        $pending = $campaign->sends()->whereNull('sent_at')->whereNull('failed_at')->count();

        if ($pending === 0) {
            $campaign->forceFill([
                'status' => Campaign::STATUS_SENT,
                'sent_at' => now(),
                'sent_count' => $campaign->sends()->whereNotNull('sent_at')->count(),
                'failed_count' => $campaign->sends()->whereNotNull('failed_at')->count(),
            ])->save();
        }
    }
}
