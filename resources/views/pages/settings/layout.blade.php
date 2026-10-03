@props(['heading' => null, 'subheading' => null])

{{--
    The account area, in dbelo's clothes.

    ── WHAT WAS WRONG ───────────────────────────────────────────────────────

    This file was the starter kit's, untouched since install: a flux:navlist
    and two flux headings. That alone would have been cosmetic — the real
    problem was one line further out. None of the three settings components
    declared #[Layout], so Livewire fell back to its default, which is the
    starter kit's own app layout. A signed-in visitor clicking "Settings"
    left dbelo entirely: different header, different sidebar, no player.

    The fix is in two halves and both matter. Each page now declares
    #[Layout('layouts.site')], so the surrounding chrome is the site's. This
    file is only the inner shell.

    ── WHY THE SITE CHROME AND NOT AN ADMIN-STYLE ONE ───────────────────────

    Because the person reading this is a visitor, not staff. They arrived
    from a sound page, they may have audio playing, and they are going back
    to the catalogue when they are done. Dropping them into a separate
    application to change their name breaks all three.

    ── FORM CONTROLS STAY FLUX ──────────────────────────────────────────────

    Deliberate, not laziness: login and register already use flux:input, and
    two styles of text field on the same account is worse than one style
    inherited from a package. The shell is what identifies the site.
--}}

@php
    /*
     * One definition of the sections. A second copy — in a footer link, a
     * dropdown — is the copy that will still list a page after it is
     * renamed.
     */
    $sections = [
        ['route' => 'profile.edit', 'label' => __('Profile'), 'icon' => 'user'],
        // Second, not last. It is the section people come looking for —
        // which plan am I on, when does it renew, how many downloads do I
        // have left — and for months there was nowhere to look.
        ['route' => 'plan.show', 'label' => __('Plan'), 'icon' => 'credit-card'],
        ['route' => 'security.edit', 'label' => __('Security'), 'icon' => 'shield-halved'],
        ['route' => 'appearance.edit', 'label' => __('Appearance'), 'icon' => 'palette'],
    ];
@endphp

<div class="mx-auto max-w-4xl py-8">

    <div class="mb-8">
        <div class="micro">{{ __('Account') }}</div>
        <h1 class="mt-2 text-3xl font-semibold tracking-tight">{{ __('Settings') }}</h1>
        <p class="mt-2 text-ink/60 dark:text-paper/60">
            {{ __('Your details, how you sign in, and how dbelo looks to you.') }}
        </p>
    </div>

    <div class="flex flex-col gap-8 md:flex-row md:items-start">

        {{-- On a phone this becomes a scrolling row of pills rather than a
             stacked list: three items do not deserve a third of the screen
             above the thing you came to change. --}}
        <nav class="-mx-1 flex gap-1.5 overflow-x-auto px-1 pb-1 md:mx-0 md:w-52 md:shrink-0 md:flex-col md:overflow-visible md:px-0 md:pb-0"
             aria-label="{{ __('Settings') }}">

            @foreach ($sections as $section)
                @php $active = request()->routeIs($section['route']); @endphp

                <a href="{{ route($section['route']) }}" wire:navigate
                   @class([
                       'flex shrink-0 items-center gap-2.5 rounded-full px-4 py-2.5 text-[0.88rem] transition duration-200 ease-dbelo md:rounded-control',
                       'bg-brand text-white' => $active,
                       'bg-surface shadow-soft-sm hover:-translate-y-0.5 dark:bg-surface-dark' => ! $active,
                   ])
                   @if ($active) aria-current="page" @endif>
                    <x-icon :name="$section['icon']" :style="$active ? 'solid' : 'regular'" class="text-[0.85rem]" />
                    {{ $section['label'] }}
                </a>
            @endforeach
        </nav>

        <div class="min-w-0 flex-1">
            @if ($heading)
                <h2 class="text-[1.15rem] font-medium">{{ $heading }}</h2>

                @if ($subheading)
                    <p class="mt-1 max-w-[62ch] text-[0.88rem] leading-relaxed text-ink/55 dark:text-paper/55">{{ $subheading }}</p>
                @endif
            @endif

            <div class="mt-5 rounded-card bg-surface p-6 shadow-soft-md sm:p-7 dark:bg-surface-dark">
                {{ $slot }}
            </div>
        </div>
    </div>
</div>
