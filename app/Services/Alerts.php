<?php

namespace App\Services;

use App\Models\Claim;
use App\Support\Email;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The two things worth interrupting somebody for.
 *
 * Both are immediate, and both were chosen because their value is gone by
 * tomorrow: a backup that stopped running is discovered on the day you need
 * it, and a copyright claim has a clock on it in most of the places this
 * site will be read.
 *
 * Everything else the panel knows — new error groups, abuse signals, sounds
 * waiting in the queue — is deliberately NOT here. Those are worth a look
 * once a day, not an interruption, and one email per event is how a person
 * trains themselves to filter the folder these arrive in. The day they are
 * added they should arrive as one daily digest, not as themselves.
 *
 * NOTHING IN THIS CLASS THROWS. An alert that can break the operation it is
 * reporting on turns a failed backup into a failed backup command, and a
 * copyright claim into a form the complainant could not submit. The failure
 * goes to the log, where a missing alert can still be traced.
 */
class Alerts
{
    /**
     * Not more than one backup alert a day.
     *
     * The check runs hourly. Without this the same broken backup would send
     * twenty-four identical emails before anybody woke up, and the
     * twenty-fifth would be filtered along with the rest.
     */
    private const BACKUP_COOLDOWN_KEY = 'alerts.backup.sent';

    public function backupFailed(string $reason): void
    {
        if (! Email::flag('alert_backup')) {
            return;
        }

        $this->send(
            'Backup failed',
            "The database backup did not complete.\n\n{$reason}",
            route('admin.backups'),
            'Open Backups',
        );
    }

    /**
     * Nothing has been backed up for too long.
     *
     * Deliberately separate from backupFailed(): a command that crashes and
     * a command that silently never runs look identical from the outside,
     * and the second one is the more common. Only this check catches it.
     */
    public function backupOverdue(int $hours): void
    {
        if (! Email::flag('alert_backup')) {
            return;
        }

        if (Cache::get(self::BACKUP_COOLDOWN_KEY)) {
            return;
        }

        $sent = $this->send(
            'No recent database backup',
            "The newest copy of the database is {$hours} hours old.\n\n"
            ."Either the hourly command is not running, or it has been failing quietly. "
            ."Nothing is lost yet — but nothing new is being protected either.",
            route('admin.backups'),
            'Open Backups',
        );

        if ($sent) {
            Cache::put(self::BACKUP_COOLDOWN_KEY, true, now()->addDay());
        }
    }

    /**
     * Somebody has asked for a sound to be taken down.
     */
    public function claimReceived(Claim $claim): void
    {
        if (! Email::flag('alert_claim')) {
            return;
        }

        $subject = trim((string) ($claim->work_title ?? $claim->subject_label ?? '')) ?: 'a sound';
        $from = trim((string) ($claim->claimant_name ?? $claim->name ?? '')) ?: 'somebody';

        $this->send(
            'New copyright claim',
            "{$from} has asked for {$subject} to be taken down.\n\n"
            .'Claims usually carry a deadline to respond. This one is waiting in the panel.',
            route('admin.claims'),
            'Open Claims',
        );
    }

    /* ═══════════════════════════ Sending ═══════════════════════════ */

    /**
     * @return bool  whether it actually went out
     */
    private function send(string $subject, string $body, string $url, string $action): bool
    {
        try {
            $to = Email::alertRecipient();

            if (! $to) {
                Log::warning('Alert not sent: no admin email is set', ['subject' => $subject]);

                return false;
            }

            Mail::send('mail.alert', [
                'heading' => $subject,
                'body' => $body,
                'url' => $url,
                'action' => $action,
            ], function ($message) use ($to, $subject) {
                $message->to($to)->subject('['.config('app.name').'] '.$subject);
            });

            return true;
        } catch (Throwable $e) {
            Log::error('Alert could not be sent', [
                'subject' => $subject,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
