@props([
    /** What the setting is called, in the operator's words rather than the key's. */
    'label',

    /**
     * One line: what changes on the site when this changes.
     *
     * Required by convention, not by code. A settings screen without it is a
     * list of boxes whose effects you find out by trying them on the live
     * site — which is how a setting nobody understands ends up never being
     * touched, or touched once, badly.
     */
    'affects' => null,

    /** The config default, shown so that "empty" is a legible state. */
    'default' => null,

    /** Validation key, so the message lands under its own field. */
    'name' => null,

    /** Optional warning shown under the control, for a setting with a cost. */
    'warning' => null,

    /**
     * True when the value is stored but nothing reads it yet.
     *
     * Added after a settings screen shipped with two fields whose consumers
     * did not exist — the value saved, the site did not change, and nothing
     * anywhere said why. A control that looks connected and is not is worse
     * than a missing control: it costs an afternoon to disprove.
     *
     * The honest fix is to wire the consumer. Where the consumer is a
     * feature not built yet, this chip says so on the field itself.
     */
    'pending' => false,

    /**
     * Where the value actually lands, and where it deliberately does not.
     *
     * For the settings with a narrower reach than their name suggests. "Site
     * description" sounds like it governs every page; it governs the pages
     * that do not describe themselves, and the difference is invisible until
     * somebody checks the one page that overrides it and concludes the field
     * is broken. Spelling both lists out is cheaper than that afternoon.
     *
     * @var array<int, string>
     */
    'usedBy' => [],
    'notUsedBy' => [],
])

<div class="grid gap-3 px-5 py-5 md:grid-cols-[minmax(0,20rem)_1fr] md:gap-8">
    <div class="min-w-0">
        <div class="flex flex-wrap items-center gap-2">
            <span class="text-[0.88rem] text-paper/85">{{ $label }}</span>

            @if ($pending)
                <span class="rounded-full bg-warning/15 px-2 py-0.5 text-[0.66rem] uppercase tracking-[0.12em] text-warning">
                    Not used yet
                </span>
            @endif
        </div>

        @if ($affects)
            <p class="mt-1 max-w-[46ch] text-[0.78rem] leading-relaxed text-paper/40">{{ $affects }}</p>
        @endif
    </div>

    <div class="min-w-0">
        {{ $slot }}

        @if ($name)
            @error($name)
                <p class="mt-1.5 text-[0.74rem] text-danger">{{ $message }}</p>
            @enderror
        @endif

        @if ($warning)
            <p class="mt-2 flex items-start gap-2 text-[0.78rem] leading-relaxed text-warning/85">
                <x-icon name="triangle-exclamation" style="solid" class="mt-[0.15rem] shrink-0 text-[0.7rem]" />
                <span>{{ $warning }}</span>
            </p>
        @endif

        @if ($usedBy || $notUsedBy)
            <div class="mt-3 grid gap-x-6 gap-y-2 rounded-xl bg-rail px-4 py-3 sm:grid-cols-2">
                @if ($usedBy)
                    <div>
                        <div class="text-[0.68rem] uppercase tracking-[0.14em] text-paper/30">Where it appears</div>
                        <ul class="mt-1.5 space-y-1">
                            @foreach ($usedBy as $where)
                                <li class="flex items-start gap-2 text-[0.78rem] leading-relaxed text-paper/50" wire:key="u-{{ $loop->index }}">
                                    <x-icon name="check" style="solid" class="mt-[0.2rem] shrink-0 text-[0.65rem] text-success" />
                                    <span>{{ $where }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if ($notUsedBy)
                    <div>
                        <div class="text-[0.68rem] uppercase tracking-[0.14em] text-paper/30">Where it does not</div>
                        <ul class="mt-1.5 space-y-1">
                            @foreach ($notUsedBy as $where)
                                <li class="flex items-start gap-2 text-[0.78rem] leading-relaxed text-paper/40" wire:key="n-{{ $loop->index }}">
                                    <x-icon name="minus" style="solid" class="mt-[0.2rem] shrink-0 text-[0.65rem] text-paper/25" />
                                    <span>{{ $where }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        @endif

        @if (filled($default))
            <p class="mt-2 text-[0.74rem] text-paper/25">
                Leave empty to use <span class="font-mono text-paper/40">{{ $default }}</span>
            </p>
        @endif
    </div>
</div>
