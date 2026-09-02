@php
    /**
     * The wording, in one place.
     *
     * Four reasons, four different problems, and the difference matters: a
     * visitor who used their three free files needs a sign-up button, a
     * member who hit today's quota needs a plan, and telling either of them
     * the other one's story is how a page like this stops being read.
     *
     * Nothing here scolds. Somebody standing on this page tried to take a
     * sound from a library of sounds, which is the behaviour the whole site
     * is built to produce.
     */
    $copy = match ($reason) {
        'account' => [
            'icon' => 'user-plus',
            'title' => 'An account is needed to download',
            'lead' => 'Listening is open to everyone. Downloading is the part that needs a name attached, because every file carries a licence and the licence has to be granted to somebody.',
        ],
        'premium' => [
            'icon' => 'crown',
            'title' => 'This one is part of a plan',
            'lead' => 'Most of the catalogue is free. A small part of it is not, and this sound is in that part.',
        ],
        'quota' => [
            'icon' => 'hourglass-half',
            'title' => "That's today's downloads used",
            'lead' => 'Your allowance resets at midnight. Nothing has been taken away — everything you already downloaded is still yours to keep and to re-download.',
        ],
        default => [
            'icon' => 'circle-check',
            'title' => $allowance === 1
                ? 'That was your free download'
                : 'That was the last of your '.$allowance.' free downloads',
            'lead' => $prompt,
        ],
    };
@endphp

<x-layouts::site>

    <div class="mx-auto max-w-[54rem] px-5 py-16 sm:py-24">

        {{-- ══════════════════════════════════════════════════════════════
             THE ANSWER, FIRST
             ══════════════════════════════════════════════════════════════ --}}
        <div class="rounded-card bg-surface p-8 shadow-soft-md sm:p-11 dark:bg-surface-dark">

            <span class="grid size-14 place-items-center rounded-full bg-brand/10 text-brand">
                <x-icon :name="$copy['icon']" style="solid" class="text-[1.15rem]" />
            </span>

            <h1 class="mt-6 text-[1.75rem] leading-tight font-medium sm:text-[2.1rem]">{{ $copy['title'] }}</h1>

            <p class="mt-3 max-w-[58ch] text-[1rem] leading-relaxed text-ink/55 dark:text-paper/55">
                {{ $copy['lead'] }}
            </p>

            {{-- Where they stand, in numbers rather than adjectives. --}}
            @if ($user)
                @if ($plan && ! $plan->isUnlimited())
                    <p class="mt-5 text-[0.88rem] text-ink/45 dark:text-paper/45">
                        <span class="font-medium text-ink/70 dark:text-paper/70">{{ $usedToday }}</span>
                        of {{ $plan->daily_download_limit }} today, on the {{ $plan->name }} plan.
                    </p>
                @endif
            @elseif ($guestsAllowed && $reason === 'used-up')
                <p class="mt-5 text-[0.88rem] text-ink/45 dark:text-paper/45">
                    <span class="font-medium text-ink/70 dark:text-paper/70">{{ $taken }}</span>
                    of {{ $allowance }} free downloads used.
                </p>
            @endif

            {{-- ══════════════════════════════════════════════════════════
                 WHAT TO DO NEXT
                 ══════════════════════════════════════════════════════════
                 One primary button, and it is the one that fits the reason.
                 Two buttons of equal weight is a question, and somebody who
                 came here for a file should not be asked one. --}}
            <div class="mt-9 flex flex-wrap items-center gap-3">
                @guest
                    <a href="{{ route('register') }}" wire:navigate
                       class="flex items-center gap-2.5 rounded-full bg-action px-8 py-3.5 font-medium text-white transition duration-300 ease-dbelo hover:-translate-y-0.5 hover:brightness-110">
                        <x-icon name="arrow-right" style="solid" class="text-[0.85rem]" />
                        Create a free account
                    </a>

                    <a href="{{ route('login') }}" wire:navigate
                       class="rounded-full bg-ink/[0.05] px-7 py-3.5 text-[0.9rem] text-ink/70 transition duration-300 ease-dbelo hover:bg-ink/[0.09] dark:bg-paper/[0.08] dark:text-paper/70 dark:hover:bg-paper/[0.14]">
                        I already have one
                    </a>
                @else
                    {{-- A signed-in member has nothing to sign up for. If
                         there is a paid plan to move to, that is the button;
                         if there is not, saying so beats a link to a pricing
                         section that does not exist yet. --}}
                    @if ($upgrade)
                        <a href="{{ route('home') }}#pricing"
                           class="flex items-center gap-2.5 rounded-full bg-action px-8 py-3.5 font-medium text-white transition duration-300 ease-dbelo hover:-translate-y-0.5 hover:brightness-110">
                            <x-icon name="arrow-up-right" style="solid" class="text-[0.85rem]" />
                            See plans — from {{ $upgrade->priceForHumans() }}
                        </a>
                    @endif

                    <a href="{{ route('sounds.index') }}" wire:navigate
                       class="rounded-full bg-ink/[0.05] px-7 py-3.5 text-[0.9rem] text-ink/70 transition duration-300 ease-dbelo hover:bg-ink/[0.09] dark:bg-paper/[0.08] dark:text-paper/70 dark:hover:bg-paper/[0.14]">
                        Keep browsing
                    </a>
                @endguest
            </div>

            {{-- The way back to the exact sound. They came here wanting one
                 file; losing which one is a small betrayal. --}}
            @if ($sound)
                <p class="mt-7 border-t border-ink/[0.07] pt-6 text-[0.86rem] text-ink/45 dark:border-paper/[0.09] dark:text-paper/45">
                    You were after
                    <a href="{{ route('sounds.show', $sound) }}" wire:navigate
                       class="font-medium text-ink/75 underline underline-offset-4 transition hover:text-action dark:text-paper/75">{{ $sound->title }}</a>.
                    It will be waiting.
                </p>
            @endif
        </div>

        {{-- ══════════════════════════════════════════════════════════════
             WHY IT IS WORTH IT — only for the people it is an offer to
             ══════════════════════════════════════════════════════════════ --}}
        @guest
            @php
                $benefits = [
                    ['heart', 'Favourites and collections', 'Keep the sounds you liked, and group them per project instead of finding them again.'],
                    ['clock-rotate-left', 'Your downloads, saved', 'Every file you took stays on your account with the licence you accepted, and re-downloading it never costs you anything.'],
                    ['waveform-lines', 'The whole free catalogue', 'A daily allowance instead of a handful, with no card and nothing to cancel.'],
                ];
            @endphp

            <div class="mt-6 grid gap-4 sm:grid-cols-3">
                @foreach ($benefits as [$icon, $heading, $detail])
                    <div class="rounded-card bg-surface p-6 shadow-soft-sm dark:bg-surface-dark">
                        <span class="grid size-10 place-items-center rounded-full bg-brand/10 text-brand">
                            <x-icon :name="$icon" style="solid" class="text-[0.9rem]" />
                        </span>
                        <div class="mt-4 text-[0.95rem] font-medium">{{ $heading }}</div>
                        <p class="mt-1.5 text-[0.84rem] leading-relaxed text-ink/50 dark:text-paper/50">{{ $detail }}</p>
                    </div>
                @endforeach
            </div>
        @endguest

    </div>
</x-layouts::site>
