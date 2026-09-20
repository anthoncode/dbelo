@props([
    'text',
    'label' => 'Copy link',
    'done' => 'Copied',
])

{{--
    A button that puts something on the clipboard.

    ── WHY THIS EXISTS AS A COMPONENT ───────────────────────────────────────

    Because the copying is not the one line it looks like, and it was written
    out by hand in three places until one of them broke.

    `navigator.clipboard` only exists in a SECURE CONTEXT: https, or
    localhost. A Herd site on http://dbelo.test is neither, so the whole
    `clipboard` object is undefined there and reaching for `.writeText` on it
    throws a TypeError before anything is copied. It is not a permissions
    problem and there is no prompt to accept — the API simply is not present.

    So every copy button needs the fallback below, and the way to guarantee
    that is to have one button.

    ── THE FALLBACK ─────────────────────────────────────────────────────────

    `document.execCommand('copy')` is deprecated and works everywhere,
    including over plain http, which is exactly the combination that matters
    here. It copies the current selection, so the text has to exist in the
    document first: hence the throwaway textarea, positioned fixed and
    invisible so it cannot scroll the page while it is selected.

    ── THIS COMPONENT BRINGS NO LOOKS ───────────────────────────────────────

    No default classes. Whatever `class` the caller passes lands on the
    button untouched, because the two places using it sit on different
    surfaces at different sizes, and merging Tailwind classes does not
    resolve conflicts — both survive and the stylesheet order decides, which
    is not what the caller meant. The icon sizes itself in `em`, so it
    follows whatever font size the caller chose.

    share-menu has its own copy of this logic on purpose: there, copying also
    has to close the menu it lives in. If the fallback ever changes, it
    changes in both.
--}}
<button
    type="button"
    x-data="{
        copied: false,

        async copy() {
            const value = @js($text);

            try {
                await navigator.clipboard.writeText(value);
            } catch (error) {
                const field = document.createElement('textarea');
                field.value = value;
                field.style.position = 'fixed';
                field.style.opacity = '0';
                document.body.appendChild(field);
                field.select();
                document.execCommand('copy');
                field.remove();
            }

            this.copied = true;
            setTimeout(() => this.copied = false, 1800);
        },
    }"
    x-on:click="copy()"
    {{ $attributes }}
>
    {{-- Wrapped in spans because x-icon takes `style` as a prop (the Font
         Awesome family), so a second style attribute meant for CSS collides
         with it and one of them is silently dropped. --}}
    <span x-show="! copied"><x-icon name="link" style="solid" class="text-[0.8em] text-brand" /></span>
    <span x-show="copied" style="display: none"><x-icon name="check" style="solid" class="text-[0.8em] text-success" /></span>

    <span x-text="copied ? @js($done) : @js($label)">{{ $label }}</span>
</button>
