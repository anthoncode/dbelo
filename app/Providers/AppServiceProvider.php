<?php

namespace App\Providers;

use App\Listeners\RecordAuthEvents;
use App\Listeners\RecordScheduledTaskFailure;
use App\Models\Setting;
use App\Services\AudioProcessor;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /*
         * AudioProcessor, told where ffmpeg and ffprobe are.
         *
         * Its constructor defaults to the bare names 'ffmpeg' and 'ffprobe',
         * which the container would use as-is — and bare names only resolve
         * when the binaries are on the PATH. On a host where nobody can run
         * `apt install ffmpeg`, a static build in the account's own home
         * directory works fine but is invisible to a PATH lookup.
         *
         * Bound here rather than read inside the class so the class stays
         * testable with two paths of a test's choosing, and so this is the
         * single place that knows about configuration.
         */
        $this->app->bind(AudioProcessor::class, fn () => new AudioProcessor(
            config('dbelo.audio.ffmpeg'),
            config('dbelo.audio.ffprobe'),
        ));
    }

    public function boot(): void
    {
        /*
         * The access log.
         *
         * Subscribed to Laravel's own auth events rather than hooked into a
         * login controller: Fortify, passkeys and the two-factor challenge
         * all sign people in by different routes, but every one of them
         * fires these. A login path added later is therefore recorded
         * without anybody remembering to add it.
         */
        Event::subscribe(RecordAuthEvents::class);

        /*
         * Scheduled tasks that fail, recorded with the task's name.
         *
         * Without this, every failing Schedule::call() and Schedule::job()
         * in the application reports the same anonymous sentence —
         * "Scheduled command [] failed with exit code [1]." — from the same
         * line of Laravel, into one error group that cannot say which task
         * broke. Laravel knows which one; it passes the whole task object in
         * this event, and nobody was listening.
         *
         * The listener also makes the returns-false case legible: a closure
         * that returns false is recorded as a failure with nothing thrown
         * and nothing in the log, which is the hardest version of this to
         * diagnose and the easiest to write by accident.
         */
        /*
         * Event and listener both, spelled out.
         *
         * The one-argument form — Event::listen(SomeListener::class) — asks
         * the framework to infer the event from the handle() type hint, and
         * whether it does depends on the version. A registration that
         * silently does not register is the worst kind: nothing errors, and
         * the thing it was meant to record simply never appears.
         */
        Event::listen(ScheduledTaskFailed::class, RecordScheduledTaskFailure::class);

        $this->applySettings();

        // AFTER applySettings: the mail transport reads site.name for the
        // sender name, and that is one of the values applySettings lays over
        // config. Reversed, the first email of every boot would go out
        // signed with the name from .env.
        \App\Support\Email::apply();

        $this->configureAlerts();
        $this->configureDefaults();
        $this->configureRateLimiting();
    }

    /**
     * The one alert that cannot wait for a schedule.
     *
     * Hooked to the model rather than to the form: the copyright form is one
     * way a claim arrives today, and staff entering one by hand — or an
     * import, or an API later — are ways it could arrive tomorrow. A
     * listener on creation catches all of them without anybody remembering
     * to add a line.
     *
     * Registered here rather than inside the model so App\Models\Claim stays
     * a model. Alerts::claimReceived never throws, so a mail server that is
     * down cannot turn a complainant's submission into an error page.
     */
    protected function configureAlerts(): void
    {
        \App\Models\Claim::created(function ($claim) {
            app(\App\Services\Alerts::class)->claimReceived($claim);
        });
    }

    /**
     * What a setting overwrites once somebody has changed it.
     *
     * The whole point of this map. Without it a "site name" saved in the
     * panel would be a value only the settings screen knows about, and every
     * view in the project would still print the one from the config file —
     * two definitions of one fact, which is the bug class this project keeps
     * running into. With it there is exactly ONE reader everywhere,
     * config(), and the stored value simply wins.
     *
     * A key may land in more than one place: site.name also becomes app.name
     * so the layouts, the mail sender and the page titles that already read
     * it pick the change up without being touched.
     *
     * DELIBERATELY ABSENT: app.url and app.timezone.
     *
     *   app.url       is what signs download links and builds password-reset
     *                 URLs. Letting a database row move it is how a reset
     *                 link starts pointing at another host with nothing
     *                 failing anywhere.
     *   app.timezone  decides how timestamps are WRITTEN. dbelo.timezone
     *                 only decides which day a stored moment is counted in.
     *                 Conflating them would silently reinterpret every row
     *                 already in the database.
     *
     * PUBLIC because Diagnostics reads it: this map is only half a wire.
     * The other half is a view actually calling config() on the target, and
     * nothing enforces that — the first version of this shipped with two
     * settings whose consumers did not exist, which looked exactly like a
     * broken save. Diagnostics::settingsWired() checks the second half.
     */
    public const OVERLAY = [
        // mail.from.name is listed SEPARATELY from app.name and not implied
        // by it. config/mail.php reads env('MAIL_FROM_NAME', env('APP_NAME'))
        // when the config file is evaluated, which happens long before this
        // provider boots — so overwriting app.name afterwards leaves the
        // sender name frozen at whatever env said. Two config keys, both
        // written, because one does not follow the other.
        'site.name' => ['dbelo.site.name', 'app.name', 'mail.from.name'],
        'site.description' => ['dbelo.site.description'],
        'site.footer' => ['dbelo.site.footer'],
        'site.admin_email' => ['dbelo.site.admin_email'],
        'site.status' => ['dbelo.site.status'],
        'site.status_message' => ['dbelo.site.status_message'],
        'legal.support_email' => ['dbelo.legal.support_email'],
        'timezone' => ['dbelo.timezone'],
    ];

    /**
     * Settings that are stored on purpose for something not built yet.
     *
     * Declared rather than tolerated. Without this list Diagnostics would
     * warn about them for months, and a warning that is permanently lit is
     * one nobody reads — which is how the real one gets missed. Declaring it
     * also puts the "Not used yet" chip on the field itself, so the screen
     * admits it instead of the operator having to find out by testing.
     *
     * A key leaves this list the day its consumer is written.
     */
    public const NOT_YET_CONSUMED = [
        // site.admin_email used to be here. Its consumer now exists —
        // App\Services\Alerts sends the backup and copyright-claim alerts
        // to it — so the entry came out in the same commit that built the
        // sender. Diagnostics would have caught it either way: a key listed
        // here that something reads is reported as a stale declaration,
        // because the field would still be wearing a chip saying it does
        // nothing.
    ];

    /**
     * Lay the stored settings over the config defaults.
     *
     * One cached array, read once per request. Wrapped: a settings table
     * that cannot be read must leave the site running on its defaults, not
     * take it down — this runs on every request including the ones made
     * while the database is being restored.
     */
    protected function applySettings(): void
    {
        try {
            $stored = Setting::cached();
        } catch (\Throwable) {
            return;
        }

        foreach (self::OVERLAY as $key => $targets) {
            $value = $stored[$key] ?? null;

            // Absent and empty both mean "nobody changed this", which is what
            // the settings screen writes when a field is cleared.
            if ($value === null || $value === '') {
                continue;
            }

            foreach ($targets as $target) {
                config([$target => $value]);
            }
        }
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        $this->configureUploads();

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * Raise Livewire's ceiling on temporary uploads.
     *
     * Livewire validates every temporary upload against
     * `livewire.temporary_file_upload.rules`, and when that config is absent
     * — which it is, because config/livewire.php was never published — it
     * falls back to a built-in `max:12288`. Twelve megabytes.
     *
     * That is a sensible default for a site uploading avatars and a hard
     * wall for one uploading audio: a 21-second WAV at 48 kHz is already
     * 11.86 MB. The file is rejected before any of this application's own
     * validation runs, so the message is Livewire's, not ours, and the whole
     * batch it arrived with fails alongside it.
     *
     * Set here rather than by publishing the config file: this is the single
     * line that matters, and it stays visible next to the reason for it.
     * The value is in KILOBYTES, matching Laravel's `max` rule.
     */
    protected function configureUploads(): void
    {
        config([
            'livewire.temporary_file_upload.rules' => [
                'required',
                'file',
                'max:204800',   // 200 MB — the real ceiling is PHP's, below
            ],

            // Minutes a temporary upload survives before cleanup. Five is
            // not much when twenty files are queued behind ffmpeg.
            'livewire.temporary_file_upload.max_upload_time' => 15,
        ]);
    }

    /**
     * The plan limit stops a user downloading too much. These limits stop a
     * script hammering the server: different problem, different defence.
     */
    protected function configureRateLimiting(): void
    {
        // Generous for a human clicking download, impossible for a scraper
        // walking the whole catalogue.
        RateLimiter::for('downloads', fn (Request $request) => [
            Limit::perMinute(20)->by($request->user()?->id ?: $request->ip()),
            Limit::perHour(200)->by($request->user()?->id ?: $request->ip()),
        ]);

        // Live search fires on every keystroke after a debounce, so this has
        // to sit well above normal typing.
        RateLimiter::for('search', fn (Request $request) => Limit::perMinute(120)
            ->by($request->user()?->id ?: $request->ip()));

        // Uploads are heavy: each one spawns an ffmpeg job.
        RateLimiter::for('uploads', fn (Request $request) => Limit::perHour(120)
            ->by($request->user()?->id ?: $request->ip()));
    }
}
