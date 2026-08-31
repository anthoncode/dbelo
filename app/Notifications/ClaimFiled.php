<?php

namespace App\Notifications;

use App\Models\Claim;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a contributor that one of their sounds has been claimed.
 *
 * The Contributor Agreement §3 makes them responsible for claims over what
 * they upload, and §3 also says they cover the cost of defending one. None
 * of that is fair if they hear about it after the fact.
 *
 * Sent by hand from the admin screen, not automatically: some claims are
 * obvious nonsense and there is no reason to alarm anyone over those.
 */
class ClaimFiled extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Claim $claim) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $sound = $this->claim->sound;

        $message = (new MailMessage)
            ->subject("A rights claim was filed over: {$sound->title}")
            ->greeting('Hi there')
            ->line("Someone has claimed rights over **{$sound->title}**, which you uploaded to dbelo.")
            ->line('**What they claim:** '.$this->claim->rightLabel())
            ->line('**Reference:** '.$this->claim->reference());

        if ($this->claim->sound_taken_down_at) {
            $message->line('We have taken the sound offline while we look into it. Its page stays up with a notice, so nothing you linked to is broken.');
        } else {
            $message->line('The sound is still online. We are reviewing the claim before deciding anything.');
        }

        return $message
            ->line('If you recorded this yourself, or you hold a licence for it, reply to this email with whatever shows that — the recording session, the source you licensed it from, the release you were given. That is usually enough to close a claim.')
            // Not a threat, a fact they agreed to. Saying it now is kinder
            // than saying it after they have ignored three emails.
            ->line('The Contributor Agreement makes you responsible for the rights to what you upload, so your answer matters here.')
            ->action('Read the Contributor Agreement', route('legal.contributor'))
            ->salutation('— dbelo');
    }
}
