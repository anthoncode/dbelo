@php
    $user = auth()->user();
@endphp

{{--
    The account, top right, where every panel ever built puts it.

    IT MOVED HERE FROM THE SIDEBAR rather than being copied. The sidebar
    block that held it is now just the collapse control — which is what it
    always really was, with a name and an avatar decorating it. Two avatars
    on one screen, both opening the same profile, is the same mistake as the
    two controls for one transport on the email screen: the second one does
    not add discoverability, it adds a question about whether they differ.
--}}
<div x-data="{ open: false }" class="relative" @keydown.escape.window="open = false">

    <button type="button" @click="open = ! open"
            :aria-expanded="open ? 'true' : 'false'"
            class="flex items-center gap-2.5 rounded-lg py-1 pl-1 pr-2 transition hover:bg-paper/[0.07]"
            :class="open ? 'bg-paper/[0.07]' : ''">

        <span class="grid size-8 shrink-0 place-items-center rounded-lg bg-brand text-[0.7rem] font-semibold text-white">
            {{ $user?->initials() }}
        </span>

        <span class="hidden min-w-0 text-left md:block">
            <span class="block max-w-[10rem] truncate text-[0.82rem] leading-tight text-paper/85">{{ $user?->name }}</span>
            <span class="block text-[0.68rem] capitalize leading-tight text-paper/35">{{ $user?->role }}</span>
        </span>

        <x-icon name="chevron-down" style="regular"
                class="shrink-0 text-[10px] text-paper/30 transition-transform duration-200"
                ::class="open ? 'rotate-180' : ''" />
    </button>

    <div x-show="open" x-cloak x-transition.opacity.duration.150ms
         @click.outside="open = false"
         class="absolute right-0 top-[calc(100%+0.5rem)] z-50 w-60 overflow-hidden rounded-2xl border border-hairline bg-panel"
         style="box-shadow: 0 20px 60px rgba(0,0,0,.5);">

        <div class="border-b border-hairline px-4 py-3">
            <div class="truncate text-[0.86rem] text-paper/85">{{ $user?->name }}</div>
            <div class="truncate text-[0.75rem] text-paper/35">{{ $user?->email }}</div>
        </div>

        <div class="p-1.5">
            @foreach ([
                ['user', 'Profile', route('profile.edit')],
                ['palette', 'Appearance', route('appearance.edit')],
                ['shield-check', 'Password & 2FA', route('security.edit')],
            ] as [$icon, $label, $url])
                <a href="{{ $url }}" wire:navigate
                   class="flex items-center gap-3 rounded-lg px-3 py-2 text-[0.84rem] text-paper/60 transition hover:bg-paper/[0.06] hover:text-paper"
                   wire:key="acct-{{ $loop->index }}">
                    <x-icon :name="$icon" style="regular" class="w-4 shrink-0 text-center text-[13px]" />
                    {{ $label }}
                </a>
            @endforeach
        </div>

        {{-- Log out is separated by a rule, not just spacing.

             It is the one item here you cannot undo with the back button,
             and it sits directly under three you can. --}}
        <div class="border-t border-hairline p-1.5">
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit"
                        class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-left text-[0.84rem] text-paper/60 transition hover:bg-danger/10 hover:text-danger">
                    <x-icon name="arrow-right-from-bracket" style="regular" class="w-4 shrink-0 text-center text-[13px]" />
                    Log out
                </button>
            </form>
        </div>
    </div>
</div>
