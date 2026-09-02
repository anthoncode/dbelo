<?php

namespace App\Support;

use App\Support\Concerns\SettingFields;

/**
 * How mail leaves this site, and who hears from it.
 *
 * Same shape as Homepage and Security: one map the settings screen renders
 * from and the application reads through.
 *
 * THE PASSWORD AND THE API KEY ARE SECRETS. Encrypted at rest, never
 * returned to the browser — see the `secret` type and the reasoning in the
 * Security screen, which is the same reasoning: a Livewire public property
 * is serialised into the page.
 */
class Email
{
    use SettingFields;

    /**
     * @var array<string, array{key: string, type: string, label: string, help: string}>
     */
    public const FIELDS = [

        /* ══════════════════════════ Transport ══════════════════════════ */

        'mailer' => [
            'key' => 'mail.transport',
            'type' => 'select',
            'label' => 'How mail leaves the site',
            'help' => 'Log writes every email into a file on this server and sends nothing at all — right while building, and the wrong thing to still be on the day you publish, when no password reset would ever arrive. SMTP and Resend both really send.',
            'options' => [
                'log' => 'Log only — nothing is sent',
                'smtp' => 'SMTP — any provider',
                'resend' => 'Resend — API',
            ],
        ],
        'fallback' => [
            'key' => 'mail.fallback',
            'type' => 'select',
            'label' => 'If that fails, try',
            'help' => 'Laravel can try a second carrier when the first one refuses. Worth knowing: a fallback HIDES the first one breaking — mail keeps arriving, so nothing tells you SMTP has been down for a week. Falling back to Log is the honest middle: nothing is delivered, but nothing is lost either, and the message is sitting in the file when you go looking.',
            'options' => [
                'none' => 'Nothing — let it fail',
                'log' => 'Log — keep the message, deliver nothing',
                'smtp' => 'SMTP',
                'resend' => 'Resend',
            ],
        ],
        'from_address' => [
            'key' => 'mail.from_address',
            'type' => 'text',
            'label' => 'Sender address',
            'help' => 'For password resets, verification links and everything else a person is waiting for.',
        ],
        'from_name' => [
            'key' => 'mail.from_name',
            'type' => 'text',
            'label' => 'Sender name',
            'help' => 'Blank uses the site name from General, so there is one name to change rather than two that disagree.',
        ],
        'marketing_from' => [
            'key' => 'mail.marketing_from',
            'type' => 'text',
            'label' => 'Marketing sender',
            'help' => 'Newsletters and offers leave from here instead. If a campaign collects spam complaints the reputation damage lands on this address, and the emails people actually need keep arriving.',
        ],

        /* ════════════════════════════ SMTP ════════════════════════════ */

        'smtp_host' => [
            'key' => 'mail.smtp.host',
            'type' => 'text',
            'label' => 'Host',
            'help' => '',
        ],
        'smtp_port' => [
            'key' => 'mail.smtp.port',
            'type' => 'number',
            'label' => 'Port',
            'help' => '587 with TLS is the usual pair. 465 wants SSL; 25 is almost always blocked.',
        ],
        'smtp_encryption' => [
            'key' => 'mail.smtp.encryption',
            'type' => 'select',
            'label' => 'Encryption',
            'help' => '',
            'options' => [
                'tls' => 'TLS',
                'ssl' => 'SSL',
                'none' => 'None',
            ],
        ],
        'smtp_username' => [
            'key' => 'mail.smtp.username',
            'type' => 'text',
            'label' => 'Username',
            'help' => '',
        ],
        'smtp_password' => [
            'key' => 'mail.smtp.password',
            'type' => 'secret',
            'label' => 'Password',
            'help' => 'Encrypted in the database and never sent back to this screen. To change it, type a new one.',
        ],

        /* ═══════════════════════════ Resend ═══════════════════════════ */

        'resend_key' => [
            'key' => 'mail.resend.key',
            'type' => 'secret',
            'label' => 'API key',
            'help' => 'Encrypted, and never sent back here. Resend keeps its own delivery log with the bounces and complaints this server cannot see — which is exactly why the panel has no email log of its own.',
        ],

        /* ═══════════════════════════ Alerts ═══════════════════════════ */

        'alert_backup' => [
            'key' => 'mail.alerts.backup',
            'type' => 'bool',
            'label' => 'Backup failed or overdue',
            'help' => 'Immediate. A backup that stopped running is discovered on the day you need it, and that is the one day it is too late.',
            'default_on' => true,
        ],
        'alert_claim' => [
            'key' => 'mail.alerts.claim',
            'type' => 'bool',
            'label' => 'New copyright claim',
            'help' => 'Immediate, and the only one here with a legal clock behind it. Without this a claim is a number on a badge that nobody sees until they next open the panel.',
            'default_on' => true,
        ],
    ];

    /**
     * @var array<string, array{label: string, note: string, fields: array<int, string>}>
     */
    public const SECTIONS = [
        'transport' => [
            'label' => 'How mail is sent',
            'note' => 'Which service actually delivers your email, and what name it arrives under.',

            /*
             * `mailer` is NOT in this list, and it is still a real setting.
             *
             * It used to render here as a dropdown — and then each transport
             * section grew a "Use SMTP" button, which meant two controls
             * deciding one thing. That is the same duplication this project
             * keeps removing everywhere else, and it produced exactly the
             * symptom it always produces: you could not tell from the SMTP
             * section whether SMTP was on.
             *
             * The switch on each transport section is now the only control.
             * Turning one on turns the other off, because a message leaves
             * by one carrier or the other, never both. Neither on means Log:
             * nothing is delivered. That is not a hidden fourth state — it
             * is what "no carrier is switched on" already means.
             */
            'fields' => ['fallback', 'from_address', 'from_name', 'marketing_from'],
        ],
        'smtp' => [
            'label' => 'SMTP',
            'note' => 'Your own server, or any provider that gives you a host and a password.',
            'fields' => ['smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_password'],
        ],
        'resend' => [
            'label' => 'Resend',
            'note' => 'One API key instead of four fields, and it keeps its own delivery log.',
            'fields' => ['resend_key'],
        ],
        'alerts' => [
            'label' => 'Alerts',
            'note' => 'Sent to the admin email in Settings → General. Both are immediate: they are the two whose value is gone by tomorrow.',
            'fields' => ['alert_backup', 'alert_claim'],
        ],
    ];

    /* ═══════════════════════════ Applying ═══════════════════════════ */

    /**
     * Lay the stored settings over config('mail.*').
     *
     * Called from AppServiceProvider so it applies to web requests, queued
     * jobs and console commands alike. Mail leaves from all three, and a
     * transport configured in only one of them is a site where the password
     * reset works and the queued welcome email does not.
     *
     * That is also why the SMTP password is decrypted at boot rather than
     * lazily, unlike the Google client secret. Google is needed on exactly
     * two routes, so keeping it out of config the rest of the time is free.
     * Mail can be sent from anywhere, so the lazy version would have to be
     * repeated in three places — and the third one is always the one that
     * gets forgotten.
     */
    public static function apply(): void
    {
        $transport = self::choice('mailer');

        // Nothing configured: leave .env in charge. This is the state a
        // fresh clone is in, and it must keep working.
        if (blank(self::text('mailer'))) {
            return;
        }

        config(['mail.default' => $transport === 'log' ? 'log' : $transport]);

        if (filled(self::text('from_address'))) {
            config(['mail.from.address' => self::text('from_address')]);
        }

        config([
            'mail.from.name' => filled(self::text('from_name'))
                ? self::text('from_name')
                : config('app.name'),
        ]);

        if (filled(self::text('marketing_from'))) {
            config(['dbelo.mail.marketing_from' => self::text('marketing_from')]);
        }

        if ($transport === 'smtp') {
            config([
                'mail.mailers.smtp.host' => self::text('smtp_host'),
                'mail.mailers.smtp.port' => self::number('smtp_port') ?: 587,
                'mail.mailers.smtp.username' => self::text('smtp_username'),
                'mail.mailers.smtp.password' => self::text('smtp_password'),
                // Laravel expects null, not the string "none".
                'mail.mailers.smtp.encryption' => self::choice('smtp_encryption') === 'none'
                    ? null
                    : self::choice('smtp_encryption'),
            ]);
        }

        if ($transport === 'resend') {
            config(['resend.api_key' => self::text('resend_key')]);
        }

        /*
         * The second carrier.
         *
         * Laravel's own `failover` transport: it tries each mailer in order
         * and moves on when one throws. Nothing hand-rolled — a retry loop
         * written here would have to understand which failures are worth
         * retrying, and getting that wrong means either a message sent twice
         * or a message quietly dropped.
         *
         * Configured only when it names a DIFFERENT mailer. A failover from
         * smtp to smtp is a list that retries the thing that just refused.
         */
        $fallback = self::choice('fallback');

        if ($fallback !== 'none' && $fallback !== $transport) {
            config([
                'mail.mailers.failover' => [
                    'transport' => 'failover',
                    'mailers' => [$transport, $fallback],
                ],
                'mail.default' => 'failover',
            ]);
        }
    }

    /**
     * Where an alert goes.
     *
     * The admin email from Settings → General — the field that carried a
     * "Not used yet" chip until this screen existed. This is its consumer,
     * and the chip comes off in the same commit.
     */
    public static function alertRecipient(): ?string
    {
        $address = (string) config('dbelo.site.admin_email');

        return filter_var($address, FILTER_VALIDATE_EMAIL) ? $address : null;
    }

    /**
     * What is actually carrying mail right now, in one answer.
     *
     * Built because "how do I know SMTP is on?" is a fair question that the
     * screen could not answer: the select shows what you CHOSE, and a choice
     * with no host and no password delivers nothing. Chosen and working are
     * two different states and the screen was only showing one.
     *
     * @return array<string, mixed>
     */
    public static function status(): array
    {
        $transport = self::choice('mailer');
        $fallback = self::choice('fallback');

        $label = self::FIELDS['mailer']['options'][$transport] ?? $transport;

        [$ready, $detail] = match ($transport) {
            'smtp' => blank(self::text('smtp_host'))
                ? [false, 'No host is set, so nothing can be delivered.']
                : (self::hasSecret('smtp_password') || blank(self::text('smtp_username'))
                    ? [true, self::text('smtp_host').':'.(self::number('smtp_port') ?: 587)
                        .' over '.strtoupper(self::choice('smtp_encryption'))]
                    : [false, 'There is a username but no password.']),

            'resend' => self::hasSecret('resend_key')
                ? [true, 'Through the Resend API.']
                : [false, 'No API key is set, so nothing can be delivered.'],

            default => [false, 'Every email is written to storage/logs/laravel.log. Nothing leaves this server.'],
        };

        return [
            'transport' => $transport,
            'label' => $label,
            'ready' => $ready,
            'detail' => $detail,
            'fallback' => $fallback !== 'none' && $fallback !== $transport
                ? (self::FIELDS['fallback']['options'][$fallback] ?? $fallback)
                : null,
            'last_test' => self::lastTest(),
        ];
    }

    /**
     * The outcome of the last test send, remembered.
     *
     * A test you ran once and cannot see afterwards proves nothing tomorrow.
     * Stored rather than cached: "it last worked three weeks ago" is exactly
     * the sentence worth surviving a restart.
     *
     * @return array{at: string, state: string}|null
     */
    public static function lastTest(): ?array
    {
        $raw = \App\Models\Setting::read('mail.last_test');

        if (blank($raw)) {
            return null;
        }

        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) && isset($decoded['at'], $decoded['state']) ? $decoded : null;
    }

    public static function recordTest(string $state): void
    {
        \App\Models\Setting::put('mail.last_test', json_encode([
            'at' => now()->toIso8601String(),
            'state' => $state,
        ]), 'mail');
    }

    /** Is the transport something other than "write it to a file"? */
    public static function sends(): bool
    {
        return config('mail.default') !== 'log' && config('mail.default') !== 'array';
    }
}
