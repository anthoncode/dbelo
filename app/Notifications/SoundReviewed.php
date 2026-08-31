<?php

namespace App\Notifications;

use App\Models\Sound;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a contributor what happened to their upload.
 *
 * Queued, so a slow mail server never holds up the moderation screen.
 */
class SoundReviewed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Sound $sound,
        public string $decision,   // published | rejected
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->decision === 'published'
            ? $this->published()
            : $this->rejected();
    }

    protected function published(): MailMessage
    {
        return (new MailMessage)
            ->subject("Your sound is live: {$this->sound->title}")
            ->greeting('Good news')
            ->line("**{$this->sound->title}** passed review and is now in the dbelo catalogue.")
            ->action('Listen to it', route('sounds.show', $this->sound))
            ->line('Thanks for contributing.');
    }

    protected function rejected(): MailMessage
    {
        return (new MailMessage)
            ->subject("Changes needed: {$this->sound->title}")
            ->greeting('Hi there')
            ->line("**{$this->sound->title}** did not pass review this time.")
            // The reason is the whole point of the email. Without it the
            // contributor re-uploads the same file.
            ->line('**Reason:** '.($this->sound->rejection_reason ?? 'No reason given.'))
            ->action('Upload a new version', route('upload'))
            ->line('Fix the issue and send it again — we would love to have it.');
    }
}
