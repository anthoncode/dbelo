<x-layouts::auth :title="__('Confirm password')">
    <div class="flex flex-col gap-6">
        <x-auth-header
            :title="__('Confirm it is you')"
            :description="__('You are about to change how you sign in. Enter your password once more before continuing.')"
        />

        <x-auth-session-status class="text-center" :status="session('status')" />

        <x-passkey-verify
            options-route="passkey.confirm-options"
            submit-route="passkey.confirm"
            :label="__('Confirm with passkey')"
            :loading-label="__('Confirming...')"
            :separator="__('Or confirm with password')"
        />

        <form method="POST" action="{{ route('password.confirm.store') }}" class="flex flex-col gap-6">
            @csrf

            <flux:input
                name="password"
                :label="__('Password')"
                type="password"
                required
                autocomplete="current-password"
                :placeholder="__('Password')"
                viewable
            />

            <flux:button variant="primary" type="submit" class="w-full" data-test="confirm-password-button">
                {{ __('Confirm') }}
            </flux:button>
        </form>

        {{--
            THE DEAD END THIS AVOIDS.

            An account created through Google has a password — a random one
            generated at sign-up, which nobody knows, including its owner.
            That is the right design (a null password would eventually reach
            a Hash::check), but it means this screen is impossible for them:
            they are asked for something that does not exist, on the way to
            the only page where they could set one.

            The way out is the ordinary reset link, sent to the same Google
            address they just proved they own. Obvious once you know; a
            locked door until somebody says it.
        --}}
        @if (Route::has('password.request'))
            <p class="text-center text-[0.83rem] leading-relaxed text-ink/50 dark:text-paper/50">
                {{ __('Signed up with Google and never set a password?') }}
                <a href="{{ route('password.request') }}" wire:navigate class="text-brand underline underline-offset-2 hover:opacity-80">
                    {{ __('Create one here') }}
                </a>
                {{ __('— the link goes to the same address.') }}
            </p>
        @endif
    </div>
</x-layouts::auth>
