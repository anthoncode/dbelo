{{--
    ══════════════════════════════════════════════════════════════════════
    CONFIRM YOUR EMAIL
    ══════════════════════════════════════════════════════════════════════

    The page people land on the second they finish signing up, and again
    later if they try to download before confirming.

    ── WHAT WAS WRONG WITH IT ───────────────────────────────────────────

    It was the starter kit's, untouched since the first commit: one
    sentence, a resend button, a log out button. Four things it never said,
    all of which are the reason somebody is stuck on it:

      · WHICH ADDRESS. The most common reason a verification email never
        arrives is that it went to the address that was typed, and the
        address that was typed has a typo in it. Printing it is the whole
        fix — nobody can spot a mistake they are not shown.
      · That the link expires, so an old one in the inbox is not the one
        to click.
      · Where it lands when it is not in the inbox, which is the spam
        folder roughly always.
      · That the site still works. Verification only gates downloading and
        uploading; browsing and listening never needed it. Without saying
        so, this screen reads as a locked door.

    ── WHY IT STILL USES FLUX AND x-layouts::auth ───────────────────────

    Because login, register, reset-password and the two-factor challenge
    all do. The value here was never the styling — it was that the page
    said almost nothing. A page that looks like its four siblings and
    answers the question is worth more than a beautiful one that does not.
--}}
<x-layouts::auth :title="__('Confirm your email')">
    <div class="flex flex-col gap-6">
        <x-auth-header
            :title="__('Confirm your email address')"
            :description="__('One link, one click, and the account is yours.')" />

        {{-- The address, shown because a typo is invisible until it is on
             screen. Not editable here: changing it is a different action
             with different consequences, and it lives in the account
             settings behind a confirmed password. --}}
        @auth
            <div class="rounded-xl bg-zinc-100 px-4 py-3 text-center dark:bg-zinc-800/60">
                <div class="text-xs uppercase tracking-[0.14em] text-zinc-500 dark:text-zinc-500">
                    {{ __('Sent to') }}
                </div>
                <div class="mt-1 break-all font-medium text-zinc-800 dark:text-zinc-100">
                    {{ auth()->user()->email }}
                </div>
            </div>
        @endauth

        {{-- Fortify flashes exactly this string from the resend route. --}}
        @if (session('status') === 'verification-link-sent')
            <flux:text class="text-center font-medium !text-green-600 dark:!text-green-400">
                {{ __('Sent. It should arrive within a minute.') }}
            </flux:text>
        @endif

        {{-- Anything else flashed here, such as the message the download
             gate sets when it sends somebody this way. --}}
        @if (session('status') && session('status') !== 'verification-link-sent')
            <flux:text class="text-center">{{ session('status') }}</flux:text>
        @endif

        <div class="flex flex-col items-center gap-3">
            <form method="POST" action="{{ route('verification.send') }}" class="w-full">
                @csrf
                <flux:button type="submit" variant="primary" class="w-full">
                    {{ __('Send the link again') }}
                </flux:button>
            </form>

            {{-- The escape hatch, and it matters: this is not a locked
                 door. Only downloading and uploading wait for the
                 confirmation — everything else is open, and somebody who
                 came to listen should be able to go and listen. --}}
            <flux:link :href="route('sounds.index')" wire:navigate class="text-sm">
                {{ __('Keep browsing sounds') }}
            </flux:link>
        </div>

        <div class="space-y-2 border-t border-zinc-200 pt-5 text-sm text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
            <p class="font-medium text-zinc-700 dark:text-zinc-300">{{ __('Not arrived?') }}</p>
            <ul class="list-disc space-y-1.5 pl-5">
                <li>{{ __('Look in spam. A first email from a site you just joined lands there more often than not.') }}</li>
                <li>{{ __('Check the address above for a typo. If it is wrong, the email went to whoever owns that one.') }}</li>
                <li>{{ __('Use the newest link. Older ones expire, so an earlier email in the inbox will not work.') }}</li>
            </ul>
        </div>

        <div class="text-center">
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <flux:button variant="ghost" type="submit" class="text-sm cursor-pointer" data-test="logout-button">
                    {{ __('Log out') }}
                </flux:button>
            </form>
        </div>
    </div>
</x-layouts::auth>
