<x-layouts::auth :title="__('Register')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Create an account')" :description="__('Enter your details below to create your account')" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        {{-- A Google sign-up skips this whole form, so it goes above it. --}}
        <x-google-button label="{{ __('Sign up with Google') }}" />

        <form method="POST" action="{{ route('register.store') }}" class="flex flex-col gap-6">
            @csrf
            <!-- Name -->
            <flux:input
                name="name"
                :label="__('Name')"
                :value="old('name')"
                type="text"
                required
                autofocus
                autocomplete="name"
                :placeholder="__('Full name')"
            />

            <!-- Email Address -->
            <flux:input
                name="email"
                :label="__('Email address')"
                :value="old('email')"
                type="email"
                required
                autocomplete="email"
                placeholder="email@example.com"
            />

            <!-- Password -->
            <flux:input
                name="password"
                :label="__('Password')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('Password')"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            <!-- Confirm Password -->
            <flux:input
                name="password_confirmation"
                :label="__('Confirm password')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('Confirm password')"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            {{-- Ticked by default, but visible and in plain words.
                 A pre-ticked box someone can see and untick produces a
                 fraction of the spam complaints that silent enrolment does,
                 and almost the same number of subscribers. --}}
            <label class="flex cursor-pointer items-start gap-2.5">
                <input type="checkbox" name="newsletter" value="1" checked
                       class="mt-0.5 size-4 shrink-0 rounded border-zinc-300 text-brand focus:ring-brand dark:border-zinc-600 dark:bg-zinc-800" />
                <span class="text-sm leading-relaxed text-zinc-600 dark:text-zinc-400">
                    {{ __('Send me new sounds and the occasional offer.') }}
                    <span class="block text-xs text-zinc-500 dark:text-zinc-500">
                        {{ __('One email a week at most. Unsubscribe in one click, any time.') }}
                    </span>
                </span>
            </label>

            {{-- The form that matters most: a bot account is a bot with a
                 free download quota. --}}
            <x-captcha form="register" />

            <div class="flex items-center justify-end">
                <flux:button type="submit" variant="primary" class="w-full" data-test="register-user-button">
                    {{ __('Create account') }}
                </flux:button>
            </div>
        </form>

        <div class="space-x-1 rtl:space-x-reverse text-center text-sm text-zinc-600 dark:text-zinc-400">
            <span>{{ __('Already have an account?') }}</span>
            <flux:link :href="route('login')" wire:navigate>{{ __('Log in') }}</flux:link>
        </div>
    </div>
</x-layouts::auth>
