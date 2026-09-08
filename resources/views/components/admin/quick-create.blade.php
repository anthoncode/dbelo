{{--
    The one button for "I want to add something".

    WHY IT IS HERE AND NOT IN THE SIDEBAR. Every screen in this panel is a
    place; this is a verb. Adding a sound meant navigating to Sounds, then
    finding Bulk upload, which is three deliberate steps for the thing done
    most often in a sound library. The sidebar is still the way to browse —
    this is the shortcut for the four things that create something new.

    PACKS ARE NOT IN THIS LIST, and that is not an oversight. There is no
    admin/packs/create route: a pack is made from the form beside the table
    on the Packs screen, following the add-left/table-right convention. An
    item here saying "New pack" would open a list, not a form — a menu entry
    that lies about where it goes, which is worse than one that is missing.
    If packs get a create route later, this is a two-line change.

    Painted in ACTION, not brand. Brand is identity — the mark, the playhead,
    active states — and action is the primary button, everywhere. This is the
    primary button of the panel. It is the tinted-not-solid version because a
    solid orange circle beside four grey icons stops being a button and
    becomes a warning light.
--}}
<div x-data="{ open: false }" class="relative" @keydown.escape.window="open = false">

    <button type="button" @click="open = ! open"
            :aria-expanded="open ? 'true' : 'false'"
            aria-label="Create something"
            title="Create"
            class="grid size-9 shrink-0 place-items-center rounded-lg bg-action/[0.14] text-action transition hover:bg-action hover:text-white"
            :class="open ? 'bg-action text-white' : ''">
        <x-icon name="plus" style="solid" class="text-[13px]" />
    </button>

    <div x-show="open" x-cloak x-transition.opacity.duration.150ms
         @click.outside="open = false"
         class="absolute right-0 top-[calc(100%+0.5rem)] z-50 w-60 overflow-hidden rounded-2xl border border-hairline bg-panel"
         style="box-shadow: 0 20px 60px rgba(0,0,0,.5);">

        <div class="p-1.5">
            @foreach ([
                ['cloud-arrow-up', 'Upload sounds', 'admin.bulk-upload'],
                ['pen-nib', 'New blog post', 'admin.blog.create'],
                ['file-lines', 'New page', 'admin.pages.create'],
                ['bullhorn', 'New campaign', 'admin.campaigns.create'],
            ] as [$icon, $label, $routeName])
                {{-- Route::has() rather than a bare route() call. This
                     component is chrome on every screen in the panel, so a
                     route removed or renamed somewhere else would otherwise
                     take down every page at once instead of quietly dropping
                     one line from one menu. --}}
                @if (Route::has($routeName))
                    <a href="{{ route($routeName) }}" wire:navigate
                       @click="open = false"
                       class="flex items-center gap-3 rounded-lg px-3 py-2 text-[0.84rem] text-paper/60 transition hover:bg-paper/[0.06] hover:text-paper"
                       wire:key="qc-{{ $loop->index }}">
                        <x-icon :name="$icon" style="regular" class="w-4 shrink-0 text-center text-[13px]" />
                        {{ $label }}
                    </a>
                @endif
            @endforeach
        </div>
    </div>
</div>
