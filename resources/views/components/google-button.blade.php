@props([
    'label' => 'Continue with Google',
])

{{--
    Nothing at all until it can work.

    Security::googleReady() wants the switch AND both credentials. A button
    that is on but unconfigured sends the visitor to Google and brings them
    back to an error page that is ours — which is worse than no button,
    because it looks like the site is broken rather than like the feature
    does not exist yet.

    The mark is inline SVG, and it is the one place in this project where
    that is right: it is Google's trademark, its four colours are fixed by
    their brand terms, and it must not change with our theme or wait on a
    font file. Every other icon on the site is Font Awesome.
--}}
@if (\App\Support\Security::googleReady())
    <a href="{{ route('auth.google.redirect') }}"
       class="flex w-full items-center justify-center gap-3 rounded-lg border border-zinc-200 bg-white px-4 py-2.5 text-sm font-medium text-zinc-700 transition hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200 dark:hover:bg-zinc-700">
        <svg class="size-[18px] shrink-0" viewBox="0 0 24 24" aria-hidden="true">
            <path fill="#4285F4" d="M23.5 12.3c0-.9-.1-1.5-.2-2.2H12v4h6.6c-.1 1.1-.9 2.7-2.4 3.8l-.1.2 3.5 2.7.2.1c2.2-2.1 3.5-5.1 3.5-8.6z"/>
            <path fill="#34A853" d="M12 24c3.2 0 5.9-1.1 7.8-2.9l-3.7-2.9c-1 .7-2.3 1.2-4.1 1.2-3.1 0-5.8-2.1-6.7-5l-.2.1-3.6 2.8-.1.2C3.3 21.3 7.3 24 12 24z"/>
            <path fill="#FBBC05" d="M5.3 14.4c-.2-.7-.4-1.5-.4-2.4s.1-1.6.3-2.4V9.5L1.6 6.7l-.1.1C.5 8.3 0 10.1 0 12s.5 3.7 1.5 5.2l3.8-2.8z"/>
            <path fill="#EB4335" d="M12 4.7c2.2 0 3.7.9 4.6 1.7l3.3-3.2C17.9 1.3 15.2 0 12 0 7.3 0 3.3 2.7 1.5 6.7l3.8 2.9c.9-2.9 3.6-4.9 6.7-4.9z"/>
        </svg>

        {{ $label }}
    </a>

    <div class="flex items-center gap-3 text-xs text-zinc-400 dark:text-zinc-500">
        <span class="h-px flex-1 bg-zinc-200 dark:bg-zinc-700"></span>
        <span>{{ __('or') }}</span>
        <span class="h-px flex-1 bg-zinc-200 dark:bg-zinc-700"></span>
    </div>
@endif
