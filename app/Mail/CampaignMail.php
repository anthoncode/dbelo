<?php

namespace App\Mail;

use App\Models\Campaign;
use App\Models\Subscriber;
use App\Services\DigestBuilder;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

class CampaignMail extends Mailable
{
    public function __construct(
        public Campaign $campaign,
        public Subscriber $subscriber,
    ) {}

    public function envelope(): Envelope
    {
        $subject = $this->campaign->isDigest()
            ? app(DigestBuilder::class)->subject($this->campaign)
            : $this->campaign->subject;

        return new Envelope(
            // Marketing goes out from a different address than password
            // resets and verification links. If a run of offers damages the
            // sender's reputation, the emails people actually need still
            // arrive.
            from: new Address(
                config('dbelo.mail.marketing_from', config('mail.from.address')),
                config('mail.from.name', 'dbelo'),
            ),
            replyTo: [new Address(config('dbelo.legal.support_email', 'support@dbelo.com'))],
            subject: $subject,
        );
    }

    /**
     * List-Unsubscribe is the single most useful header a bulk sender can
     * set. Gmail and Yahoo put a real "Unsubscribe" button next to the
     * sender when it is present, and people press that instead of "Report
     * spam" — which is the difference between losing one reader and having
     * every future email land in junk.
     *
     * Both providers now require it above a few thousand messages a day.
     */
    public function headers(): Headers
    {
        return new Headers(text: [
            'List-Unsubscribe' => '<'.$this->subscriber->unsubscribeUrl().'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
            'X-Campaign-Id' => (string) $this->campaign->id,
        ]);
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.campaign',
            with: [
                'campaign' => $this->campaign,
                'subscriber' => $this->subscriber,
                'digest' => $this->campaign->isDigest()
                    ? app(DigestBuilder::class)->build($this->campaign)
                    : null,
            ],
        );
    }
}
