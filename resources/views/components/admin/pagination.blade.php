@props([
    'paginator',
    'prefix' => 'pg',
])

{{--
    The admin's pagination, in one place.

    Laravel's own ->links() renders a light-theme Tailwind partial whose
    classes are not in this project's build, so it comes out unstyled and
    wrong. This is the same markup the hand-built tables already use.

    `prefix` keeps wire:key unique when two paginated tables can appear on
    the same screen.
--}}
@if ($paginator->hasPages())
    <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-3.5">
        <span class="text-[0.78rem] text-paper/35">
            {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} of {{ $paginator->total() }}
        </span>

        <div class="flex items-center gap-1.5">
            <x-admin.icon-button icon="chevron-left" variant="muted" label="Previous"
                                 wire:click="previousPage" @disabled($paginator->onFirstPage()) />

            @foreach ($paginator->getUrlRange(max(1, $paginator->currentPage() - 2), min($paginator->lastPage(), $paginator->currentPage() + 2)) as $page => $url)
                <button wire:click="gotoPage({{ $page }})" wire:key="{{ $prefix }}-{{ $page }}"
                        @class([
                            'grid size-8 place-items-center rounded-full text-[0.78rem] tabular-nums transition duration-200 ease-dbelo',
                            'bg-brand text-white' => $page === $paginator->currentPage(),
                            'text-paper/40 hover:bg-paper/[0.10] hover:text-paper' => $page !== $paginator->currentPage(),
                        ])>{{ $page }}</button>
            @endforeach

            <x-admin.icon-button icon="chevron-right" variant="muted" label="Next"
                                 wire:click="nextPage" @disabled(! $paginator->hasMorePages()) />
        </div>
    </div>
@endif
