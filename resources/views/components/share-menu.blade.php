@props([
    'url',
    'title' => '',
    'size' => 'size-10',
    'align' => 'right',   // right · left
    'tone' => 'light',    // light · dark  — the surface the BUTTON sits on
])

@php
    $encodedUrl = rawurlencode($url);
    $encodedText = rawurlencode($title);

    /*
     * Identity for the "only one menu open at a time" channel below.
     * Derived from the URL so it is stable across re-renders of the same
     * row — a random id would change on every Livewire update and the
     * menu would close itself.
     */
    $menuId = 'share-'.substr(md5($url), 0, 12);

    /*
     * Share targets, in the order they get used.
     *
     * WhatsApp sits first for a reason: for a Spanish-speaking audience it
     * carries more sharing than the two social networks under it combined.
     * Putting them above it would be copying a US layout rather than reading
     * the room.
     */
    $targets = [
        ['WhatsApp', 'whatsapp', 'brands', "https://wa.me/?text={$encodedText}%20{$encodedUrl}"],
        ['Facebook', 'facebook-f', 'brands', "https://www.facebook.com/sharer/sharer.php?u={$encodedUrl}"],
        ['X', 'x-twitter', 'brands', "https://twitter.com/intent/tweet?url={$encodedUrl}&text={$encodedText}"],
        ['Email', 'envelope', 'solid', "mailto:?subject={$encodedText}&body={$encodedUrl}"],
    ];

    /*
     * `tone` styles the TRIGGER only, never the dropdown.
     *
     * This used to be done by the caller with an arbitrary variant —
     * [&_button]:!text-paper/70 — which reached past the trigger and hit the
     * "Copy link" button inside the panel as well. The panel is a light
     * surface, so that painted near-white text on white and the menu read as
     * broken. A prop keeps the two surfaces separate, which is what they are.
     */
    $trigger = $tone === 'dark'
        ? 'bg-paper/[0.07] text-paper/70 hover:bg-paper/[0.14] hover:text-paper'
        : 'bg-surface text-ink/55 shadow-soft-sm hover:text-brand hover:shadow-soft-md dark:bg-surface-dark dark:text-paper/55';
@endphp

<div x-data="{
        id: @js($menuId),
        open: false,
        copied: false,
        timer: null,

        /*
         * Opens on hover, closes on a delay.
         *
         * The delay is the whole trick: the pointer has to cross a couple of
         * pixels of nothing between the button and the panel, and without it
         * the menu closes in that gap and the visitor can never reach an
         * item. Click still works — on touch there is no hover at all.
         */
        show() {
            clearTimeout(this.timer)
            this.open = true

            /*
             * Tell every other menu on the page to close.
             *
             * Without this, each row's menu is an island: nothing about
             * opening one closes the others, so any leak — a re-render, a
             * missed mouseleave, a pointer that never left the panel —
             * accumulates until the page is a staircase of open dropdowns.
             * One channel makes that state unreachable rather than rare.
             */
            window.dispatchEvent(new CustomEvent('dbelo-share-open', { detail: { id: this.id } }))
        },

        hide() {
            clearTimeout(this.timer)
            this.timer = setTimeout(() => this.open = false, 240)
        },

        close() {
            clearTimeout(this.timer)
            this.open = false
        },

        async copy() {
            try {
                await navigator.clipboard.writeText(@js($url))
            } catch (error) {
                // Clipboard access needs a secure context, which http:// on a
                // local domain is not. The fallback keeps the button working
                // in development instead of failing silently.
                const field = document.createElement('textarea')
                field.value = @js($url)
                field.style.position = 'fixed'
                field.style.opacity = '0'
                document.body.appendChild(field)
                field.select()
                document.execCommand('copy')
                field.remove()
            }

            this.copied = true
            setTimeout(() => { this.copied = false; this.close() }, 1200)
        },
     }"
     x-on:dbelo-share-open.window="if ($event.detail.id !== id) close()"
     x-on:keydown.escape.window="close()"
     x-on:click.outside="close()"
     {{-- A dropdown anchored to a row must not outlive the row's position:
          scrolling or navigating away leaves it floating over content it no
          longer belongs to. --}}
     x-on:scroll.window.passive="close()"
     x-on:livewire:navigating.window="close()"
     x-on:mouseenter="show()"
     x-on:mouseleave="hide()"
     x-on:focusin="show()"
     x-on:focusout="hide()"
     class="relative">

    <button type="button" x-on:click="open ? close() : show()"
            :aria-expanded="open"
            aria-label="Share"
            @class([
                'grid place-items-center rounded-full transition duration-300 ease-dbelo hover:-translate-y-0.5',
                $trigger,
                $size,
            ])>
        <x-icon name="share-nodes" style="solid" class="text-[0.85rem]" />
    </button>

    {{--
        style="display:none" is the closed state, NOT x-cloak.

        This is the fix for the menu that would not stay shut. x-cloak is a
        one-shot: Alpine strips the attribute the moment it initialises and
        never puts it back, so from then on the only thing hiding this panel
        is the inline `display: none` that x-show writes. Livewire's morph
        patches the live DOM toward freshly rendered server HTML — and that
        HTML carried no style attribute, so on the next re-render of the page
        (the home screen re-renders on every keystroke in the search box) the
        morph would remove the inline style and the panel would simply appear.
        Nothing ever closed it again, on any row it had happened to.

        Rendering the closed state into the server HTML means a morph can
        only ever restore "closed", which is the safe direction. x-show then
        opens it normally.

        Always a light panel with dark text, on both tones. A dropdown is a
        menu, not part of the card it opened from — and one readable menu
        beats two that each need their own contrast check.
    --}}
    <div x-show="open" style="display: none" x-transition.opacity.duration.200ms
         @class([
             'absolute z-50 top-full mt-1 w-52 overflow-hidden rounded-card bg-surface p-1.5 pt-2.5 text-ink shadow-soft-lg dark:bg-surface-dark dark:text-paper',
             'right-0' => $align === 'right',
             'left-0' => $align === 'left',
         ])>

        <button type="button" x-on:click="copy()"
                class="flex w-full items-center gap-3 rounded-control px-3.5 py-2.5 text-[0.86rem] transition duration-200 ease-dbelo hover:bg-ink/[0.05] dark:hover:bg-paper/[0.08]">
            {{-- The toggled icon is wrapped in a span so the closed state can
                 be a real `style="display:none"`. It cannot go on <x-icon>
                 itself: that component takes `style` as a PROP — it is the
                 Font Awesome weight — so a CSS style attribute there would
                 collide with it and one of the two would be dropped. --}}
            <span class="w-4 shrink-0 text-center">
                <span x-show="! copied">
                    <x-icon name="link" style="solid" class="text-[0.8rem] text-ink/45 dark:text-paper/45" />
                </span>
                <span x-show="copied" style="display: none">
                    <x-icon name="check" style="solid" class="text-[0.8rem] text-success" />
                </span>
            </span>
            <span x-text="copied ? 'Link copied' : 'Copy link'">Copy link</span>
        </button>

        @foreach ($targets as [$label, $icon, $style, $href])
            <a href="{{ $href }}" target="_blank" rel="noopener noreferrer"
               class="flex items-center gap-3 rounded-control px-3.5 py-2.5 text-[0.86rem] transition duration-200 ease-dbelo hover:bg-ink/[0.05] dark:hover:bg-paper/[0.08]">
                <span class="w-4 shrink-0 text-center">
                    <x-icon :name="$icon" :style="$style" class="text-[0.8rem] text-ink/45 dark:text-paper/45" />
                </span>
                {{ $label }}
            </a>
        @endforeach
    </div>
</div>
