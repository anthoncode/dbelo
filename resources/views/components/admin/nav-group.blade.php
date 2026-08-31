@props(['groupKey', 'group'])

@php
    $isFlat = empty($group['items']);
    $isActive = \App\Support\AdminNav::matches($group['route'] ?? null);
    $hasActiveChild = collect($group['items'])->contains(fn ($i) => \App\Support\AdminNav::matches($i['route']));
    $count = collect($group['items'])->sum(fn ($i) => $i['count'] ?? 0);
@endphp

{{-- ── Single-item group: a plain link ── --}}
@if ($isFlat)
    <div class="group/item relative">
        @if ($group['route'])
            <a href="{{ route($group['route']) }}" wire:navigate
               @class([
                   'flex items-center rounded-xl transition duration-200 ease-dbelo',
                   'bg-raised text-paper' => $isActive,
                   'text-paper/55 hover:bg-paper/[0.05] hover:text-paper' => ! $isActive,
               ])
               :class="collapsed ? 'justify-center px-0 py-3' : 'gap-3.5 px-3.5 py-3'">
                <x-icon :name="$group['icon']" :style="$isActive ? 'solid' : 'regular'"
                        class="w-4 shrink-0 text-center text-[15px] {{ $isActive ? 'text-brand' : '' }}" />
                <span x-show="! collapsed" x-cloak class="min-w-0 flex-1 truncate text-[0.9rem]">{{ $group['label'] }}</span>
            </a>
        @else
            <span class="flex cursor-default items-center text-paper/25"
                  :class="collapsed ? 'justify-center px-0 py-3' : 'gap-3.5 px-3.5 py-3'">
                <x-icon :name="$group['icon']" style="regular" class="w-4 shrink-0 text-center text-[15px]" />
                <span x-show="! collapsed" x-cloak class="min-w-0 flex-1 truncate text-[0.9rem]">{{ $group['label'] }}</span>
            </span>
        @endif

        <x-admin.tooltip :label="$group['label']" />
    </div>

@else
    {{-- ── Accordion group ── --}}
    <div class="group/item relative">
        <button type="button" @click="toggle('{{ $groupKey }}')"
                @class([
                    'flex w-full items-center rounded-xl transition duration-200 ease-dbelo',
                    'bg-raised text-paper' => $hasActiveChild,
                    'text-paper/55 hover:bg-paper/[0.05] hover:text-paper' => ! $hasActiveChild,
                ])
                :class="collapsed ? 'justify-center px-0 py-3' : 'gap-3.5 px-3.5 py-3'">

            <span class="relative shrink-0">
                <x-icon :name="$group['icon']" :style="$hasActiveChild ? 'solid' : 'regular'"
                        class="w-4 text-center text-[15px] {{ $hasActiveChild ? 'text-brand' : '' }}" />

                {{-- Collapsed, the badge has nowhere to sit but on the icon --}}
                @if ($count > 0)
                    <span x-show="collapsed" x-cloak
                          class="absolute -right-2 -top-1.5 grid h-4 min-w-4 place-items-center rounded-full bg-paper px-1 text-[0.6rem] font-bold text-ink">
                        {{ $count }}
                    </span>
                @endif
            </span>

            <span x-show="! collapsed" x-cloak class="min-w-0 flex-1 truncate text-left text-[0.9rem]">{{ $group['label'] }}</span>

            @if ($count > 0)
                <span x-show="! collapsed" x-cloak
                      class="grid size-[22px] shrink-0 place-items-center rounded-full bg-paper text-[0.68rem] font-bold text-ink">
                    {{ $count }}
                </span>
            @endif

            <x-icon name="chevron-down" style="regular" x-show="! collapsed" x-cloak
                    class="shrink-0 text-[11px] text-paper/30 transition-transform duration-300"
                    ::class="open['{{ $groupKey }}'] ? 'rotate-180' : ''" />
        </button>

        <x-admin.tooltip :label="$group['label']" />
    </div>

    {{-- Children. The dot replaces the icon: at this depth an icon per row
         is visual noise, a dot is enough to mark the rhythm. --}}
    <div x-show="open['{{ $groupKey }}'] && ! collapsed" x-cloak x-collapse class="mt-0.5 space-y-0.5">
        @foreach ($group['items'] as $item)
            @php $childActive = \App\Support\AdminNav::matches($item['route']); @endphp

            @if ($item['route'])
                <a href="{{ route($item['route']) }}" wire:navigate
                   @class([
                       'flex items-center gap-3 rounded-lg py-2 pl-[30px] pr-3.5 text-[0.86rem] transition duration-200',
                       'text-paper' => $childActive,
                       'text-paper/45 hover:text-paper' => ! $childActive,
                   ])>
                    <span @class([
                        'size-[6px] shrink-0 rounded-full transition',
                        'bg-brand' => $childActive,
                        'bg-paper/25' => ! $childActive,
                    ])></span>
                    <span class="min-w-0 flex-1 truncate">{{ $item['label'] }}</span>

                    @if (($item['count'] ?? 0) > 0)
                        <span class="grid size-[20px] shrink-0 place-items-center rounded-full bg-paper text-[0.64rem] font-bold text-ink">
                            {{ $item['count'] }}
                        </span>
                    @endif
                </a>
            @else
                <span class="flex cursor-default items-center gap-3 rounded-lg py-2 pl-[30px] pr-3.5 text-[0.86rem] text-paper/20">
                    <span class="size-[6px] shrink-0 rounded-full bg-paper/10"></span>
                    <span class="min-w-0 flex-1 truncate">{{ $item['label'] }}</span>
                    <span class="shrink-0 text-[0.58rem] uppercase tracking-[0.12em]">soon</span>
                </span>
            @endif
        @endforeach
    </div>
@endif
