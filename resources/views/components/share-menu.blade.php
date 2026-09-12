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
     * carries more sharing than the social networks under it combined.
     * Putting them above it would be copying a US layout rather than reading
     * the room.
     *
     * Telegram and Reddit are here because of WHO uses a sound library. The
     * audience is video editors, game developers, podcasters and filmmakers,
     * and they pass links around in Discord servers, Telegram groups and a
     * handful of subreddits — r/gamedev, r/filmmakers, r/podcasting. Reddit
     * in particular is where a library gets discovered rather than merely
     * mentioned.
     *
     * Discord has no share URL at all, which is exactly why "Copy link" is
     * at the top of this menu and not at the bottom: for this audience it is
     * not the fallback, it is the main one.
     */
    $targets = [
        ['WhatsApp', 'whatsapp', 'brands', "https://wa.me/?text={$encodedText}%20{$encodedUrl}"],
        ['Telegram', 'telegram', 'brands', "https://t.me/share/url?url={$encodedUrl}&text={$encodedText}"],
        ['Reddit', 'reddit-alien', 'brands', "https://www.reddit.com/submit?url={$encodedUrl}&title={$encodedText}"],
        ['X', 'x-twitter', 'brands', "https://twitter.com/intent/tweet?url={$encodedUrl}&text={$encodedText}"],
        ['Facebook', 'facebook-f', 'brands', "https://www.facebook.com/sharer/sharer.php?u={$encodedUrl}"],
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

{{--
    ══════════════════════════════════════════════════════════════════════
    WHY THIS FILE LOOKS THE WAY IT DOES
    ══════════════════════════════════════════════════════════════════════

    ── NO COMMENTS INSIDE x-data ────────────────────────────────────────

    Every note about this component lives out here, in Blade comments, and
    the x-data expression below is a compact object with none.

    The previous version documented itself INSIDE the attribute, including
    two `//` line comments. That is the bug that produced "scripts may only
    close windows that were opened by a script". A `//` comment runs to the
    end of the LINE, and an HTML attribute is not guaranteed to keep its
    line breaks — anything that collapses them turns the rest of the object
    literal into commented-out text. Alpine then cannot parse it, discards
    the component, and carries on.

    ── WHY THAT FAILURE WAS SILENT ──────────────────────────────────────

    When Alpine cannot find a name on a component it looks it up on
    `window`. The old handler read:

        open ? close() : show()

    With the component gone, `open` resolved to `window.open` — a function,
    therefore truthy — so the expression took the first branch and called
    `window.close()`. The browser refused, printed that warning, and the
    menu never opened. Every name involved happened to exist on `window`,
    so nothing ever threw.

    The properties are now isOpen / reveal() / dismiss(), which exist on no
    window anywhere. If this component ever fails to parse again, the
    console says "isOpen is not defined" and points straight at it instead
    of quietly trying to close the tab.

    ── CLICK ONLY, NO HOVER ─────────────────────────────────────────────

    Hover used to open the menu, which meant the pointer opened it on
    approach and the click that followed saw it already open and closed it
    again. Clicking could only ever close. It also popped a menu open on
    every row the pointer crossed on the way down a list.
--}}
<div x-data="{ id: @js($menuId), isOpen: false, copied: false, timer: null, reveal() { clearTimeout(this.timer); this.isOpen = true; window.dispatchEvent(new CustomEvent('dbelo-share-open', { detail: { id: this.id } })) }, dismiss() { clearTimeout(this.timer); this.isOpen = false }, async copyLink() { try { await navigator.clipboard.writeText(@js($url)) } catch (error) { const field = document.createElement('textarea'); field.value = @js($url); field.style.position = 'fixed'; field.style.opacity = '0'; document.body.appendChild(field); field.select(); document.execCommand('copy'); field.remove() } this.copied = true; setTimeout(() => { this.copied = false; this.dismiss() }, 1200) } }"
     x-on:dbelo-share-open.window="if ($event.detail.id !== id) dismiss()"
     x-on:keydown.escape.window="dismiss()"
     x-on:click.outside="dismiss()"
     {{-- A dropdown anchored to a row must not outlive the row's position:
          scrolling or navigating away leaves it floating over content it no
          longer belongs to. --}}
     x-on:scroll.window.passive="dismiss()"
     x-on:livewire:navigating.window="dismiss()"
     class="relative">

    <button type="button" x-on:click="isOpen ? dismiss() : reveal()"
            :aria-expanded="isOpen"
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

        x-cloak is a one-shot: Alpine strips the attribute the moment it
        initialises and never puts it back, so from then on the only thing
        hiding this panel is the inline `display: none` that x-show writes.
        Livewire's morph patches the live DOM toward freshly rendered server
        HTML — and that HTML carried no style attribute, so on the next
        re-render of the page the morph would remove the inline style and
        the panel would simply appear, with nothing left to close it.

        Rendering the closed state into the server HTML means a morph can
        only ever restore "closed", which is the safe direction.

        Always a light panel with dark text, on both tones. A dropdown is a
        menu, not part of the card it opened from — and one readable menu
        beats two that each need their own contrast check.
    --}}
    <div x-show="isOpen" style="display: none" x-transition.opacity.duration.200ms
         @class([
             'absolute z-50 top-full mt-1 w-52 overflow-hidden rounded-card bg-surface p-1.5 pt-2.5 text-ink shadow-soft-lg dark:bg-surface-dark dark:text-paper',
             'right-0' => $align === 'right',
             'left-0' => $align === 'left',
         ])>

        <button type="button" x-on:click="copyLink()"
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
