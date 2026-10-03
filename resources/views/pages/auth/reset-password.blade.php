{{--
    ══════════════════════════════════════════════════════════════════════
    RESET PASSWORD — set the new one
    ══════════════════════════════════════════════════════════════════════

    ── THE EDITABLE EMAIL FIELD ─────────────────────────────────────────

    It was a normal <input type="email"> prefilled from the query string,
    which is what the starter kit ships. The token is bound to the address:
    change a character in that box and the form comes back with "This
    password reset token is invalid", which is both true and useless —
    nothing on the screen connects the failure to the field they touched,
    and the obvious next move is to request another link and do it again.

    So when the address arrives in the link, it is shown as text and posted
    as a hidden field. Nothing to edit, nothing to break. When it does not
    arrive — somebody opening the bare URL — the field is there and typing
    is the only way forward.

    ── AND IT SAYS WHAT THE RESET ACTUALLY DOES ─────────────────────────

    App\Actions\Fortify\ResetUserPassword ends EVERY session, with no
    exception for the browser doing the resetting. That is deliberate and
    correct: a forgotten password is what people reset when they have lost
    control, and sparing an existing session would be sparing the one they
    are trying to remove. But it was invisible — you pressed a button and
    were signed out everywhere with no warning, which reads as a bug.
    Saying it turns the same behaviour into the reason to press the button.
--}}
<x-layouts::auth :title="__('Reset password')">
    @php
        // From the link. Falls back to whatever was posted, so a validation
        // error does not empty the form.
        $address = request('email') ?: old('email');
    @endphp

    <div class="flex flex-col gap-6">
        <x-auth-header
            :title="__('Choose a new password')"
            :description="__('This replaces the old one straight away.')" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('password.update') }}" class="flex flex-col gap-6">
            @csrf
            <!-- Token -->
            <input type="hidden" name="token" value="{{ request()->route('token') }}">

            @if (filled($address))
                {{-- Shown, not editable: the token only matches this one. --}}
                <input type="hidden" name="email" value="{{ $address }}">

                <div class="rounded-xl bg-zinc-100 px-4 py-3 dark:bg-zinc-800/60">
                    <div class="text-xs uppercase tracking-[0.14em] text-zinc-500 dark:text-zinc-500">
                        {{ __('Account') }}
                    </div>
                    <div class="mt-1 break-all font-medium text-zinc-800 dark:text-zinc-100">
                        {{ $address }}
                    </div>
                </div>

                {{-- The email field is hidden, so its error has nowhere to
                     land. This is where "invalid token" arrives. --}}
                @error('email')
                    <flux:text class="!text-red-600 dark:!text-red-400">
                        {{ $message }}
                        <span class="mt-1 block text-sm text-zinc-500 dark:text-zinc-400">
                            {{ __('Links expire and work once. Ask for a new one from the log in page.') }}
                        </span>
                    </flux:text>
                @enderror
            @else
                {{-- No address in the link. Rare — every link we send carries
                     one — but a dead end here means starting the whole
                     process again, so the field stays. --}}
                <flux:input
                    name="email"
                    :value="old('email')"
                    :label="__('Email address')"
                    type="email"
                    required
                    autofocus
                    autocomplete="email"
                />
            @endif

            <!-- Password -->
            <flux:input
                name="password"
                :label="__('New password')"
                type="password"
                required
                :autofocus="filled($address)"
                autocomplete="new-password"
                :placeholder="__('New password')"
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

            <flux:button type="submit" variant="primary" class="w-full" data-test="reset-password-button">
                {{ __('Set the new password') }}
            </flux:button>
        </form>

        <p class="border-t border-zinc-200 pt-5 text-sm text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
            {{ __('Every device that was signed in will be signed out, including this one. If someone else was in your account, this is what removes them.') }}
        </p>
    </div>
</x-layouts::auth>
