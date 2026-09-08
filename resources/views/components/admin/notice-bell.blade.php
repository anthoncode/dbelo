@php
    // See the note in nav-group: an alias, not a `use`.
    $N = \App\Support\Notices::class;

    $notices = $N::all();
    $worst = $N::worst();
    $total = count($notices);
@endphp

{{--
    The bell.

    IT HAS NO STATE OF ITS OWN. There is no read flag, no dismiss, no
    "mark all as read" — a notice is here because something is true, and it
    leaves when that stops being true. The alternative was a notifications
    table, and the first time it disagreed with the sidebar badge next to it
    neither number would have been believed again.

    Rendered server-side, straight from App\Support\Notices. No polling: the
    numbers are a minute old at most because that is how long AdminNav caches
    them, and every navigation in the panel re-renders this. An admin panel
    that pings the server every ten seconds to ask whether anything is wrong
    is a load generator with a bell on it.
--}}
<div x-data="{ open: false }" class="relative"
     @keydown.escape.window="open = false">

    <button type="button" @click="open = ! open"
            :aria-expanded="open ? 'true' : 'false'"
            aria-label="{{ $total === 0 ? 'Notifications — nothing needs you' : $total.' things need you' }}"
            class="relative grid size-9 shrink-0 place-items-center rounded-lg text-paper/45 transition hover:bg-paper/[0.07] hover:text-paper"
            :class="open ? 'bg-paper/[0.07] text-paper' : ''">

        {{-- IT RINGS ONLY FOR DANGER, AND ONLY THREE TIMES.

             Both halves of that are the point. A bell that shakes for every
             notice shakes almost always, and a control that is always moving
             is wallpaper within a day — the same way a badge that is
             permanently lit stops being read. So warnings and information
             get the coloured dot and nothing else; the motion is reserved
             for the level that means something is actually broken.

             And it stops. Three rings across about six seconds is long
             enough to catch an eye that was somewhere else and short enough
             that it is not still twitching while you work. It rings again on
             the next page — which is correct, because a danger that is still
             there on the next page is a danger you have not fixed.

             The wrapper exists so the rotation applies to the bell alone.
             Rotating the button would swing the dot around it, and the
             reduced-motion rule already in app.css turns all of this off for
             anybody who asked their system for less movement. --}}
        <span @class(['grid place-items-center', 'bell-ring' => $worst === $N::DANGER])>
            <x-icon name="bell" :style="$total > 0 ? 'solid' : 'regular'" class="text-[15px]" />
        </span>

        {{-- The dot, not a number.

             A count on the bell would compete with the counts in the sidebar
             two inches away, and they measure different things: the sidebar
             counts CLAIMS, this would count KINDS OF PROBLEM. Two numbers
             that are both right and never match is how you teach somebody
             that one of them is broken. The dot only says "there is
             something", in the colour of the worst thing. --}}
        {{-- IT BLINKS UNTIL THE LAST ONE IS GONE. Not three times, not on
             arrival only — a warning light on a dashboard, on for as long as
             the condition is true.

             This overrides the usual rule that a permanently lit badge stops
             being read, and it does so on purpose: the thing that makes a
             status light ignorable is being lit when nothing is wrong, and
             this one is not rendered at all when the list is empty. Its
             presence is already the signal; the blink is what stops it
             blending into four other static icons.

             Opacity rather than colour or size, and stepped rather than
             faded, because that is what an indicator lamp does. It also
             leaves the ring-2 halo alone — Tailwind draws that with a
             box-shadow, and animating box-shadow here would fight it.

             When it stops at 0.35 instead of 0 it is still legible mid-blink;
             a dot that vanishes completely reads as a rendering fault. The
             reduced-motion rule in app.css freezes it fully lit, which is the
             correct thing for it to fall back to. --}}
        @if ($worst)
            <span @class([
                'absolute right-1.5 top-1.5 size-2 rounded-full ring-2 ring-canvas alert-blink',
                'bg-danger' => $worst === $N::DANGER,
                'bg-warning' => $worst === $N::WARNING,
                'bg-info' => $worst === $N::INFO,
            ])></span>
        @endif
    </button>

    {{-- The panel --}}
    <div x-show="open" x-cloak x-transition.opacity.duration.150ms
         @click.outside="open = false"
         class="absolute right-0 top-[calc(100%+0.5rem)] z-50 w-[26rem] max-w-[calc(100vw-2rem)] overflow-hidden rounded-2xl border border-hairline bg-panel shadow-2xl"
         style="box-shadow: 0 20px 60px rgba(0,0,0,.5);">

        <div class="flex items-center justify-between border-b border-hairline px-4 py-3">
            <span class="text-[0.86rem] font-medium">
                {{ $total === 0 ? 'Nothing needs you' : ($total === 1 ? 'One thing needs you' : $total.' things need you') }}
            </span>

            <a href="{{ route('admin.diagnostics') }}" wire:navigate
               class="text-[0.76rem] text-paper/35 underline underline-offset-2 transition hover:text-paper">
                Diagnostics
            </a>
        </div>

        @if ($total === 0)
            {{-- The empty state says why it is empty. "No notifications" is
                 indistinguishable from a bell that is broken. --}}
            <div class="px-4 py-8 text-center">
                <span class="grid size-11 place-items-center rounded-full bg-success/10 text-success mx-auto">
                    <x-icon name="check" style="solid" class="text-[0.95rem]" />
                </span>
                <p class="mx-auto mt-3 max-w-[34ch] text-[0.82rem] leading-relaxed text-paper/45">
                    No open claims, no abuse signals, no errors this week, nothing stuck in the queue, and every
                    health check passing.
                </p>
            </div>
        @else
            <div class="max-h-[26rem] divide-y divide-hairline overflow-y-auto">
                @foreach ($notices as $notice)
                    <a href="{{ $notice['route'] ? route($notice['route']) : route('admin.diagnostics') }}"
                       wire:navigate
                       class="flex items-start gap-3 px-4 py-3.5 transition hover:bg-paper/[0.04]"
                       wire:key="notice-{{ $loop->index }}">

                        <span @class([
                            'mt-[0.1rem] grid size-8 shrink-0 place-items-center rounded-full',
                            'bg-danger/15 text-danger' => $notice['level'] === $N::DANGER,
                            'bg-warning/15 text-warning' => $notice['level'] === $N::WARNING,
                            'bg-info/15 text-info' => $notice['level'] === $N::INFO,
                        ])>
                            <x-icon :name="$notice['icon']" style="solid" class="text-[0.78rem]" />
                        </span>

                        <span class="min-w-0 flex-1">
                            <span class="block text-[0.86rem] leading-snug text-paper/85">{{ $notice['title'] }}</span>
                            <span class="mt-0.5 block text-[0.78rem] leading-relaxed text-paper/40">{{ $notice['detail'] }}</span>
                        </span>

                        <x-icon name="chevron-right" style="regular" class="mt-1.5 shrink-0 text-[0.65rem] text-paper/20" />
                    </a>
                @endforeach
            </div>

            {{-- Said once, at the bottom, rather than as a tooltip nobody
                 opens: this list has no dismiss button and that is on
                 purpose. Somebody looking for one should find the reason
                 instead of concluding it is missing. --}}
            <div class="border-t border-hairline px-4 py-2.5 text-[0.74rem] leading-relaxed text-paper/25">
                Nothing here can be dismissed — each line disappears when the thing it is about is fixed.
            </div>
        @endif
    </div>
</div>
