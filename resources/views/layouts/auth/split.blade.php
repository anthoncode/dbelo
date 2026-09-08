{{--
    The two-column sign-in page: the form on the left, a picture on the right.

    Used by Log in and Sign up only. Forgot-password, reset, two-factor and
    verify-email stay on the centred layout on purpose — they are steps in
    the middle of something, not front doors, and a promotional headline
    next to "check your email" competes with the instruction the page
    exists to give.

    THE PANEL IS DESKTOP ONLY, and that is a decision rather than a
    responsive accident. Below 1024px it is not rendered at all: somebody
    who opened this page came to sign in, and on a phone a picture above
    the form is something they have to scroll past to do it. `hidden
    lg:block` also means the browser never downloads the image on a phone,
    so the panel costs nothing on the connection that can least afford it.

    THE OVERLAY IS ALWAYS DARK, in both themes, and the headline is always
    white. The alternative — an overlay that follows the theme — puts pale
    text over a pale photograph the first time somebody uploads a bright
    one, and the failure is invisible to whoever uploaded it if their own
    machine is in dark mode. A fixed dark gradient is legible over anything
    and needs nobody to think about it.

    Both the image and the headline come from Admin → Settings →
    Appearance, and both are optional. With no image the panel is a brand
    gradient; with no headline it is just the picture. A fresh clone has
    neither and still looks finished.
--}}
@props(['title' => null])

@php
    $loginImage = \App\Support\Appearance::url('login_image');
    $loginTitle = trim(\App\Support\Appearance::text('login_title'));
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        {{-- NOT marked noindex, deliberately, and worth knowing about.

             partials/head already emits the robots tag from $seo['noindex'],
             so hiding these two pages from search is one line in each
             controller and not a meta tag written here — a second tag in
             this file would be a competing mechanism, and the two would
             disagree the day somebody changed only one of them.

             Left indexable because "dbelo sign up" is a real search and
             this is the page that should answer it. Say the word and it
             becomes noindex through the existing switch. --}}
        @include('partials.head')
    </head>

    <body class="min-h-dvh">
        <div class="grid min-h-dvh lg:grid-cols-2">

            {{-- ═══════════════════════ The form ═══════════════════════ --}}
            <div class="flex min-h-dvh flex-col px-6 py-7 sm:px-10 lg:px-14 lg:py-9">

                {{-- Top-left rather than centred, which is where the mark
                     sits on every other page of the site. site-logo already
                     shows the favicon alone below md and the full mark
                     above it, so this is the same component the header
                     uses and not a second copy that can drift from it. --}}
                <x-site-logo />

                <div class="flex flex-1 items-center justify-center py-10">
                    <div class="w-full max-w-[25rem]">
                        {{ $slot }}
                    </div>
                </div>

                {{-- Kept quiet and at the bottom. It is a legal necessity,
                     not something to read before signing in. --}}
                <p class="text-center text-[0.74rem] leading-relaxed text-ink/35 dark:text-paper/30">
                    {{ __('By continuing you agree to our') }}
                    <a href="{{ route('legal.terms') }}" class="underline underline-offset-2 transition hover:text-brand">{{ __('Terms') }}</a>
                    {{ __('and') }}
                    <a href="{{ route('legal.privacy') }}" class="underline underline-offset-2 transition hover:text-brand">{{ __('Privacy Policy') }}</a>.
                </p>
            </div>

            {{-- ═══════════════════════ The picture ═══════════════════════ --}}
            <div class="relative hidden overflow-hidden lg:block">

                @if ($loginImage)
                    {{-- Decorative, so alt is empty on purpose: the headline
                         below carries whatever meaning this panel has, and a
                         screen reader announcing "login image" would be
                         noise between the password field and the button. --}}
                    <img src="{{ $loginImage }}" alt=""
                         class="absolute inset-0 size-full object-cover" />
                @else
                    {{-- The fallback is a designed panel, not an empty box.
                         Nothing has been uploaded on a fresh install, and a
                         blank grey rectangle would read as a broken image
                         rather than as a setting nobody has filled in. --}}
                    <div class="absolute inset-0 bg-linear-to-br from-brand via-brand/70 to-action"></div>
                @endif

                {{-- The overlay. Heaviest at the bottom where the words are,
                     light at the top so the photograph is still a
                     photograph rather than a dark rectangle. --}}
                <div class="absolute inset-0 bg-linear-to-t from-black/85 via-black/40 to-black/20"></div>

                @if ($loginTitle !== '')
                    <div class="relative flex size-full items-end p-12 xl:p-14">
                        <h2 class="max-w-[20ch] text-[2.1rem] font-medium leading-[1.15] tracking-[-0.035em] text-white">
                            {{ $loginTitle }}
                        </h2>
                    </div>
                @endif
            </div>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
