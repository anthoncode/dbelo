{{--
    ══════════════════════════════════════════════════════════════════════
    FORGOT PASSWORD — ask for the link
    ══════════════════════════════════════════════════════════════════════

    ── THE LINE THAT WAS MISSING ────────────────────────────────────────

    <x-captcha form="password" />, below.

    App\Http\Middleware\VerifyCaptcha maps the POST path `forgot-password`
    to the form name `password`, and config/dbelo.php has
    security.captcha.password => true. The register form renders its widget;
    this one never did.

    Nothing happens today, because security.captcha.provider is 'off' and
    Captcha::protects() returns false before it reads any of the flags. The
    day a provider and a site key are entered — which is a settings screen,
    not a deploy — every password reset request on the site would start
    failing with "The anti-robot check did not pass. Please try again.",
    blaming the visitor for a widget that was never on the page.

    A form guarded by a check it cannot satisfy is worse than an unguarded
    form: it fails closed, silently, for the people who most need it to
    work, and the setting that broke it is three screens away.

    ── WHAT ELSE CHANGED ────────────────────────────────────────────────

    It said "Enter your email to receive a password reset link" and stopped
    there. Now it also says how long the link lasts, read from the same
    config the framework enforces rather than typed as "60 minutes" — and
    that the reset ends every open session, which is not a warning but the
    reason to do it: somebody resetting a password because they think
    another person is in their account needs to know that this is the thing
    that removes them.
--}}
<x-layouts::auth :title="__('Forgot password')">
    <div class="flex flex-col gap-6">
        <x-auth-header
            :title="__('Forgot your password?')"
            :description="__('Type the address you signed up with and we will send you a link to set a new one.')" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('password.email') }}" class="flex flex-col gap-6">
            @csrf

            <!-- Email Address -->
            <flux:input
                name="email"
                :label="__('Email address')"
                :value="old('email')"
                type="email"
                required
                autofocus
                autocomplete="email"
                placeholder="email@example.com"
            />

            {{-- The missing widget. Same component and same contract as the
                 one on the register form: it renders nothing at all while
                 the captcha is off, so adding it costs a comment and a line
                 and removes a failure that would only appear months from
                 now, on a screen nobody was touching. --}}
            <x-captcha form="password" />

            <flux:button variant="primary" type="submit" class="w-full" data-test="email-password-reset-link-button">
                {{ __('Send the link') }}
            </flux:button>
        </form>

        <div class="space-y-1.5 border-t border-zinc-200 pt-5 text-sm text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
            {{-- Read from config, not written out. The framework enforces
                 this number; a page that states it from memory is a page
                 that will be wrong the first time somebody changes it. --}}
            <p>{{ __('The link works for :minutes minutes and once only.', ['minutes' => config('auth.passwords.users.expire', 60)]) }}</p>
            <p>{{ __('Setting a new password signs out every device that was already logged in.') }}</p>
        </div>

        <div class="space-x-1 rtl:space-x-reverse text-center text-sm text-zinc-500 dark:text-zinc-400">
            <span>{{ __('Remembered it?') }}</span>
            <flux:link :href="route('login')" wire:navigate>{{ __('Log in') }}</flux:link>
        </div>
    </div>
</x-layouts::auth>
