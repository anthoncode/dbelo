@props([
    /** sound · catalog · blog */
    'name',
])

@php
    $ad = \App\Support\Ads::block($name);
@endphp

@if ($ad)
    {{--
        THE TWO THINGS THAT MAKE ADS WORK ON THIS SITE.

        1. wire:ignore.

           Livewire morphs the DOM on every update. An ad is an iframe the
           network wrote into this element, and morphing it means Livewire
           comparing our empty placeholder against the network's iframe and
           "correcting" it — the ad vanishes mid-session, on a page that
           looked fine a second ago. wire:ignore is what tells the morph to
           leave this subtree alone.

        2. Injected on intersect, once, with a guard.

           This site navigates with wire:navigate, so the <head> runs once
           and never again. AdSense scans the document at load: on the second
           page you visit there is nothing to scan, and the block stays
           blank for the rest of the session. So each block asks for itself
           when it scrolls into view.

           The `loaded` flag is not optional. Re-requesting a unit on every
           navigation is exactly the pattern Google classifies as invalid
           traffic, and the penalty for that is the account rather than the
           placement.
    --}}
    <div class="my-8 flex justify-center" wire:key="{{ $ad['id'] }}">
        @if ($ad['test'])
            {{-- Test mode: the real size, none of the network. Judging a
                 placement needs the hole, not the advert. --}}
            <div class="flex w-full max-w-[728px] flex-col items-center justify-center gap-1 rounded-xl border border-dashed border-ink/15 bg-ink/[0.03] text-ink/35 dark:border-paper/15 dark:bg-paper/[0.03] dark:text-paper/35"
                 style="min-height: {{ $ad['height'] }}px">
                <span class="text-[0.72rem] uppercase tracking-[0.18em]">Advertisement</span>
                <span class="text-[0.72rem]">{{ $name }} · {{ $ad['height'] }}px · test mode</span>
            </div>
        @else
            <div wire:ignore
                 x-data="{
                     loaded: false,

                     init() {
                         /* A plain IntersectionObserver, not x-intersect.
                            That directive is an Alpine PLUGIN and Livewire
                            does not bundle it, so the block would never fire
                            — silently, with nothing in the console, on a
                            site where nothing else uses the plugin. Twelve
                            lines of standard API beat a dependency whose
                            failure mode is doing nothing at all. */
                         const io = new IntersectionObserver((entries) => {
                             entries.forEach((entry) => {
                                 if (entry.isIntersecting) {
                                     this.place();
                                     io.disconnect();
                                 }
                             });
                         }, { rootMargin: '300px' });

                         io.observe(this.$el);
                     },

                     place() {
                         if (this.loaded) return;
                         this.loaded = true;

                         /* innerHTML does not execute <script>. The markup is
                            parsed into a template and each script re-created
                            as a real element, which is the only way a tag
                            pasted into a textarea can run at all. */
                         const html = this.$refs.code.innerHTML;
                         const frag = document.createRange().createContextualFragment(html);
                         this.$refs.mountPoint.appendChild(frag);

                         /* AdSense needs one push per unit, and only if that
                            unit has not already been filled — a second push
                            on a live slot is an error in its console and a
                            duplicate request on its side. */
                         this.$nextTick(() => {
                             this.$refs.mountPoint
                                 .querySelectorAll('ins.adsbygoogle:not([data-adsbygoogle-status])')
                                 .forEach(() => {
                                     try { (window.adsbygoogle = window.adsbygoogle || []).push({}); }
                                     catch (e) { /* blocked, offline, or no network script */ }
                                 });
                         });
                     },
                 }"
                 class="w-full max-w-[728px]"
                 style="min-height: {{ $ad['height'] }}px">

                {{-- The markup, parked in a <template> so the browser does
                     not try to run or render it before we ask. --}}
                <template x-ref="code">{!! $ad['code'] !!}</template>

                <div x-ref="mountPoint"></div>
            </div>
        @endif
    </div>
@endif
