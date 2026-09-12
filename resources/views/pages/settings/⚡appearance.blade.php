<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * Appearance.
 *
 * The component itself holds nothing, and that is correct: the choice lives
 * in the browser, not in the database. Flux's appearance store writes it to
 * localStorage and applies it before the first paint, which is what stops
 * the white flash on a dark-theme page load.
 *
 * ── WHY THIS PAGE STILL EXISTS ALONGSIDE THE HEADER TOGGLE ───────────────
 *
 * The sun/moon button in the site header is two-state: it flips between
 * light and dark and always writes an explicit choice. This page is the only
 * place that can say "system" — follow the operating system, and change on
 * its own at sunset.
 *
 * Both write the same store, so they can never disagree. That is the whole
 * reason this page keeps using $flux.appearance instead of growing its own
 * setting: one definition, two surfaces.
 */
new #[Layout('layouts.site')] #[Title('Appearance settings')] class extends Component
{
    //
}; ?>

<x-pages::settings.layout
    :heading="__('Appearance')"
    :subheading="__('Light, dark, or whatever your device is doing. Saved in this browser only — it does not follow you to another device.')">

    <flux:radio.group x-data variant="segmented" x-model="$flux.appearance">
        <flux:radio value="light" icon="sun">{{ __('Light') }}</flux:radio>
        <flux:radio value="dark" icon="moon">{{ __('Dark') }}</flux:radio>
        <flux:radio value="system" icon="computer-desktop">{{ __('System') }}</flux:radio>
    </flux:radio.group>

    <p class="mt-5 max-w-[60ch] text-[0.84rem] leading-relaxed text-ink/50 dark:text-paper/50">
        {{ __('With System selected, dbelo follows your device and changes by itself when it does — at sunset, on most phones and laptops.') }}
    </p>
</x-pages::settings.layout>
