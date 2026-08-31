<?php

namespace App\Providers;

use App\Listeners\RecordAuthEvents;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
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
        //
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

        $this->configureDefaults();
        $this->configureRateLimiting();
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
