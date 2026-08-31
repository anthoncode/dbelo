@props([
    'id',
    'title',
    'subtitle' => null,
    'icon' => 'chart-line',
    'type' => 'line',
    'colour' => 'brand',
    'labels' => [],
    'values' => [],
    'colours' => null,
    'horizontal' => false,
    'height' => 'h-64',
    'empty' => 'Nothing recorded yet',
])

{{--
    A chart in a card.

    wire:ignore on the canvas wrapper is not optional: every Livewire render
    replaces the DOM, and a replaced <canvas> is a blank chart. New numbers
    arrive through the browser event the Alpine component listens for.
--}}
<div class="rounded-2xl border border-hairline bg-panel">
    <div class="flex flex-wrap items-center gap-3 border-b border-hairline px-5 py-4">
        <x-admin.icon-chip :icon="$icon" tone="brand" />

        <div class="min-w-0 flex-1">
            <h2 class="text-[0.95rem] font-medium">{{ $title }}</h2>
            @if ($subtitle)
                <p class="mt-0.5 text-[0.75rem] text-paper/30">{{ $subtitle }}</p>
            @endif
        </div>

        {{ $actions ?? '' }}
    </div>

    <div class="p-5">
        @if (count(array_filter($values, fn ($v) => $v > 0)) === 0)
            <div class="{{ $height }} grid place-items-center">
                <div class="text-center">
                    <x-icon :name="$icon" style="regular" class="text-[22px] text-paper/15" />
                    <p class="mt-2.5 text-[0.85rem] text-paper/35">{{ $empty }}</p>
                </div>
            </div>
        @else
            <div wire:ignore class="{{ $height }} relative">
                <div x-data="dbeloChart({{ Js::from([
                        'id' => $id,
                        'type' => $type,
                        'colour' => $colour,
                        'labels' => $labels,
                        'values' => $values,
                        'colours' => $colours,
                        'horizontal' => $horizontal,
                    ]) }})" class="h-full">
                    <canvas x-ref="canvas"></canvas>
                </div>
            </div>
        @endif
    </div>
</div>
