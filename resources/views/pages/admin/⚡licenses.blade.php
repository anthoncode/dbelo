<?php

use App\Models\License;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.admin')] #[Title('Licenses')] class extends Component {
    use WithPagination;

    // ── New license ──
    public string $name = '';
    public string $summary = '';
    public bool $allowsCommercial = true;
    public bool $requiresAttribution = false;
    public bool $allowsDerivatives = true;

    // ── Inline editing ──
    public ?int $editingId = null;
    public string $editName = '';
    public string $editVersion = '';
    public string $editSummary = '';
    public string $editFullText = '';
    public string $editUrl = '';
    public bool $editCommercial = true;
    public bool $editAttribution = false;
    public bool $editDerivatives = true;

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    #[Computed]
    public function licenses()
    {
        return License::query()
            ->withCount('sounds')
            ->orderBy('id')
            ->paginate(10);
    }

    #[Computed]
    public function totals(): array
    {
        return [
            'all' => License::count(),
            'inUse' => License::has('sounds')->count(),
        ];
    }

    public function create(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:80'],
            'summary' => ['required', 'string', 'max:500'],
        ]);

        License::create([
            'name' => $this->name,
            'slug' => $this->uniqueSlug($this->name),
            'version' => '1.0',
            'summary' => $this->summary,
            'allows_commercial' => $this->allowsCommercial,
            'requires_attribution' => $this->requiresAttribution,
            'allows_derivatives' => $this->allowsDerivatives,
        ]);

        $this->reset(['name', 'summary']);
        $this->refreshLists();

        session()->flash('ok', 'License created. Add the full text before assigning it to sounds.');
    }

    public function startEdit(int $id): void
    {
        $license = License::findOrFail($id);

        $this->editingId = $id;
        $this->editName = $license->name;
        $this->editVersion = $license->version;
        $this->editSummary = $license->summary;
        $this->editFullText = (string) $license->full_text;
        $this->editUrl = (string) $license->url;
        $this->editCommercial = $license->allows_commercial;
        $this->editAttribution = $license->requires_attribution;
        $this->editDerivatives = $license->allows_derivatives;
    }

    public function cancelEdit(): void
    {
        $this->reset([
            'editingId', 'editName', 'editVersion', 'editSummary',
            'editFullText', 'editUrl', 'editCommercial',
            'editAttribution', 'editDerivatives',
        ]);
        $this->resetErrorBag();
    }

    public function saveEdit(): void
    {
        $this->validate([
            'editName' => ['required', 'string', 'max:80'],
            'editVersion' => ['required', 'string', 'max:20'],
            'editSummary' => ['required', 'string', 'max:500'],
            'editFullText' => ['nullable', 'string', 'max:20000'],
            'editUrl' => ['nullable', 'url', 'max:255'],
        ]);

        License::findOrFail($this->editingId)->update([
            'name' => $this->editName,
            'version' => $this->editVersion,
            'summary' => $this->editSummary,
            'full_text' => $this->editFullText ?: null,
            'url' => $this->editUrl ?: null,
            'allows_commercial' => $this->editCommercial,
            'requires_attribution' => $this->editAttribution,
            'allows_derivatives' => $this->editDerivatives,
        ]);

        $this->cancelEdit();
        $this->refreshLists();

        // Worth repeating on every save: this is the least intuitive part
        // of the whole licensing system.
        session()->flash('ok', 'License updated. Downloads already made keep the terms they were granted under.');
    }

    /**
     * Bumping the version is how a real change of terms is recorded. The
     * old number stays alive inside every past download snapshot.
     */
    public function bumpVersion(): void
    {
        $parts = explode('.', $this->editVersion ?: '1.0');
        $major = (int) ($parts[0] ?? 1);

        $this->editVersion = ($major + 1).'.0';
    }

    public function delete(int $id): void
    {
        $license = License::withCount('sounds')->findOrFail($id);

        if ($license->sounds_count > 0) {
            session()->flash('error', "“{$license->name}” is applied to {$license->sounds_count} sounds. Reassign them first.");

            return;
        }

        $license->delete();
        $this->refreshLists();

        session()->flash('ok', 'License deleted.');
    }

    protected function refreshLists(): void
    {
        unset($this->licenses, $this->totals);
    }

    protected function uniqueSlug(string $value): string
    {
        $base = Str::slug($value) ?: 'license';
        $slug = $base;
        $i = 2;

        while (License::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }
}; ?>

<div>
    @if (session('ok'))
        <div class="mb-5 flex items-center gap-3 rounded-xl border border-brand/30 bg-brand/10 px-4 py-3">
            <x-icon name="circle-check" style="solid" class="text-brand" />
            <span class="text-[0.88rem]">{{ session('ok') }}</span>
        </div>
    @endif

    @if (session('error'))
        <div class="mb-5 flex items-center gap-3 rounded-xl border border-hairline bg-raised px-4 py-3">
            <x-icon name="triangle-exclamation" style="solid" class="text-paper/60" />
            <span class="text-[0.88rem] text-paper/80">{{ session('error') }}</span>
        </div>
    @endif

    <div class="grid gap-5 xl:grid-cols-[340px_1fr]">

        {{-- ══════ COLUMN 1 — ADD ══════ --}}
        <div class="h-fit space-y-5">
            <div class="rounded-2xl border border-hairline bg-panel">
                <div class="border-b border-hairline px-5 py-4">
                    <h2 class="text-[0.95rem] font-medium">Add license</h2>
                </div>

                <form wire:submit="create" class="space-y-4 p-5">
                    <div>
                        <label class="mb-2 block text-[0.72rem] uppercase tracking-[0.14em] text-paper/35">Name</label>
                        <input type="text" wire:model="name" placeholder="dbelo Extended License"
                               class="w-full rounded-lg border-0 bg-raised px-3.5 py-2.5 text-[0.9rem] text-paper placeholder:text-paper/25 focus:outline-none focus:ring-2 focus:ring-brand/40" />
                        @error('name') <p class="mt-1.5 text-[0.8rem] text-brand">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="mb-2 block text-[0.72rem] uppercase tracking-[0.14em] text-paper/35">Summary</label>
                        <textarea wire:model="summary" rows="3"
                                  placeholder="One paragraph in plain language. This is what people actually read."
                                  class="w-full resize-none rounded-lg border-0 bg-raised px-3.5 py-2.5 text-[0.9rem] text-paper placeholder:text-paper/25 focus:outline-none focus:ring-2 focus:ring-brand/40"></textarea>
                        @error('summary') <p class="mt-1.5 text-[0.8rem] text-brand">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="mb-2 block text-[0.72rem] uppercase tracking-[0.14em] text-paper/35">Permissions</label>
                        <div class="flex flex-wrap gap-2">
                            <button type="button" wire:click="$toggle('allowsCommercial')"
                                    @class([
                                        'rounded-full px-3.5 py-2 text-[0.78rem] transition duration-200 ease-dbelo',
                                        'bg-brand text-white' => $allowsCommercial,
                                        'bg-raised text-paper/45 hover:text-paper' => ! $allowsCommercial,
                                    ])>Commercial</button>

                            <button type="button" wire:click="$toggle('requiresAttribution')"
                                    @class([
                                        'rounded-full px-3.5 py-2 text-[0.78rem] transition duration-200 ease-dbelo',
                                        'bg-brand text-white' => $requiresAttribution,
                                        'bg-raised text-paper/45 hover:text-paper' => ! $requiresAttribution,
                                    ])>Credit required</button>

                            <button type="button" wire:click="$toggle('allowsDerivatives')"
                                    @class([
                                        'rounded-full px-3.5 py-2 text-[0.78rem] transition duration-200 ease-dbelo',
                                        'bg-brand text-white' => $allowsDerivatives,
                                        'bg-raised text-paper/45 hover:text-paper' => ! $allowsDerivatives,
                                    ])>Modifications</button>
                        </div>
                    </div>

                    <button type="submit"
                            class="flex w-full items-center justify-center gap-2 rounded-lg bg-brand py-2.5 text-[0.88rem] font-medium text-white transition duration-200 hover:brightness-115">
                        <x-icon name="plus" style="solid" class="text-[12px]" />
                        Add license
                    </button>
                    <p class="text-center text-[0.75rem] text-paper/30">Starts at version 1.0.</p>
                </form>
            </div>

            {{-- The rule that makes licence editing safe --}}
            <div class="rounded-2xl border border-hairline bg-panel p-5">
                <div class="flex items-start gap-3">
                    <x-admin.icon-chip icon="lock" />
                    <div class="min-w-0 text-[0.82rem] leading-relaxed text-paper/55">
                        Every download stores a copy of the licence text as it stood that day.
                        Editing here changes what <strong class="text-paper/80">future</strong> downloads get —
                        never what someone already holds.
                    </div>
                </div>
            </div>
        </div>

        {{-- ══════ COLUMN 2 — LIST ══════ --}}
        <div class="rounded-2xl border border-hairline bg-panel">

            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-hairline px-5 py-3.5">
                <h2 class="text-[0.95rem] font-medium">
                    All licenses <span class="ml-1.5 text-paper/35">{{ $this->totals['all'] }}</span>
                </h2>
                <a href="{{ route('legal.licenses') }}" target="_blank"
                   class="flex items-center gap-2 rounded-lg bg-raised px-3 py-2 text-[0.8rem] text-paper/60 transition hover:bg-paper/[0.10] hover:text-paper">
                    <x-icon name="arrow-up-right-from-square" style="solid" class="text-[10px]" />
                    Public page
                </a>
            </div>

            <table class="w-full text-left">
                <thead>
                    <tr class="border-b border-hairline text-[0.68rem] uppercase tracking-[0.13em] text-paper/30">
                        <th class="w-12 px-5 py-2.5 font-medium">#</th>
                        <th class="px-3 py-2.5 font-medium">License</th>
                        <th class="w-52 px-3 py-2.5 font-medium">Permissions</th>
                        <th class="w-24 px-3 py-2.5 text-right font-medium">Sounds</th>
                        <th class="w-24 px-5 py-2.5 text-right font-medium">Actions</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-hairline">
                    @php $row = ($this->licenses->currentPage() - 1) * $this->licenses->perPage(); @endphp

                    @forelse ($this->licenses as $license)
                        @php
                            $row++;
                            $isEditing = $editingId === $license->id;
                        @endphp

                        <tr wire:key="lic-{{ $license->id }}"
                            class="transition {{ $isEditing ? 'bg-raised' : 'hover:bg-paper/[0.03]' }}">

                            @if ($isEditing)
                                {{-- The row expands into a full form: a licence
                                     carries a long legal text that cannot be
                                     edited inside a table cell. --}}
                                <td colspan="5" class="px-5 py-5">
                                    <div class="grid gap-4 lg:grid-cols-[1fr_140px]">
                                        <div>
                                            <label class="mb-2 block text-[0.72rem] uppercase tracking-[0.14em] text-paper/35">Name</label>
                                            <input type="text" wire:model="editName" autofocus
                                                   class="w-full rounded-lg border-0 bg-panel px-3.5 py-2.5 text-[0.9rem] text-paper focus:outline-none focus:ring-2 focus:ring-brand/40" />
                                            @error('editName') <p class="mt-1.5 text-[0.78rem] text-brand">{{ $message }}</p> @enderror
                                        </div>

                                        <div>
                                            <label class="mb-2 block text-[0.72rem] uppercase tracking-[0.14em] text-paper/35">Version</label>
                                            <div class="flex gap-2">
                                                <input type="text" wire:model="editVersion"
                                                       class="min-w-0 flex-1 rounded-lg border-0 bg-panel px-3 py-2.5 text-[0.9rem] tabular-nums text-paper focus:outline-none focus:ring-2 focus:ring-brand/40" />
                                                <x-admin.icon-button icon="arrow-up" variant="muted" label="Bump major version"
                                                                     wire:click="bumpVersion" />
                                            </div>
                                        </div>
                                    </div>

                                    <div class="mt-4">
                                        <label class="mb-2 block text-[0.72rem] uppercase tracking-[0.14em] text-paper/35">Summary</label>
                                        <textarea wire:model="editSummary" rows="2"
                                                  class="w-full resize-none rounded-lg border-0 bg-panel px-3.5 py-2.5 text-[0.9rem] text-paper focus:outline-none focus:ring-2 focus:ring-brand/40"></textarea>
                                        @error('editSummary') <p class="mt-1.5 text-[0.78rem] text-brand">{{ $message }}</p> @enderror
                                        <p class="mt-1.5 text-[0.75rem] text-paper/30">Shown on every sound page. Keep it human.</p>
                                    </div>

                                    <div class="mt-4">
                                        <label class="mb-2 block text-[0.72rem] uppercase tracking-[0.14em] text-paper/35">Full text</label>
                                        <textarea wire:model="editFullText" rows="10"
                                                  placeholder="The complete legal terms, shown at /licenses and frozen into every download."
                                                  class="w-full rounded-lg border-0 bg-panel px-3.5 py-2.5 font-mono text-[0.82rem] leading-relaxed text-paper placeholder:text-paper/25 focus:outline-none focus:ring-2 focus:ring-brand/40"></textarea>
                                    </div>

                                    <div class="mt-4 grid gap-4 lg:grid-cols-2">
                                        <div>
                                            <label class="mb-2 block text-[0.72rem] uppercase tracking-[0.14em] text-paper/35">Permissions</label>
                                            <div class="flex flex-wrap gap-2">
                                                <button type="button" wire:click="$toggle('editCommercial')"
                                                        @class([
                                                            'rounded-full px-3.5 py-2 text-[0.78rem] transition duration-200 ease-dbelo',
                                                            'bg-brand text-white' => $editCommercial,
                                                            'bg-panel text-paper/45 hover:text-paper' => ! $editCommercial,
                                                        ])>Commercial</button>

                                                <button type="button" wire:click="$toggle('editAttribution')"
                                                        @class([
                                                            'rounded-full px-3.5 py-2 text-[0.78rem] transition duration-200 ease-dbelo',
                                                            'bg-brand text-white' => $editAttribution,
                                                            'bg-panel text-paper/45 hover:text-paper' => ! $editAttribution,
                                                        ])>Credit required</button>

                                                <button type="button" wire:click="$toggle('editDerivatives')"
                                                        @class([
                                                            'rounded-full px-3.5 py-2 text-[0.78rem] transition duration-200 ease-dbelo',
                                                            'bg-brand text-white' => $editDerivatives,
                                                            'bg-panel text-paper/45 hover:text-paper' => ! $editDerivatives,
                                                        ])>Modifications</button>
                                            </div>
                                        </div>

                                        <div>
                                            <label class="mb-2 block text-[0.72rem] uppercase tracking-[0.14em] text-paper/35">External URL</label>
                                            <input type="url" wire:model="editUrl" placeholder="https://creativecommons.org/…"
                                                   class="w-full rounded-lg border-0 bg-panel px-3.5 py-2.5 text-[0.88rem] text-paper placeholder:text-paper/25 focus:outline-none focus:ring-2 focus:ring-brand/40" />
                                            @error('editUrl') <p class="mt-1.5 text-[0.78rem] text-brand">{{ $message }}</p> @enderror
                                        </div>
                                    </div>

                                    @if ($license->sounds_count > 0)
                                        <p class="mt-4 text-[0.8rem] text-paper/40">
                                            Applied to {{ $license->sounds_count }} {{ Str::plural('sound', $license->sounds_count) }}.
                                            If you are changing the terms rather than fixing a typo, bump the version.
                                        </p>
                                    @endif

                                    <div class="mt-5 flex items-center gap-2">
                                        <button wire:click="saveEdit"
                                                class="flex items-center gap-2 rounded-lg bg-brand px-5 py-2.5 text-[0.85rem] font-medium text-white transition hover:brightness-115">
                                            <x-icon name="check" style="solid" class="text-[11px]" />
                                            Save
                                        </button>
                                        <button wire:click="cancelEdit"
                                                class="rounded-lg bg-panel px-4 py-2.5 text-[0.85rem] text-paper/60 transition hover:text-paper">
                                            Cancel
                                        </button>
                                    </div>
                                </td>

                            @else
                                <td class="px-5 py-3 text-[0.8rem] tabular-nums text-paper/25">{{ $row }}</td>

                                <td class="px-3 py-3">
                                    <div class="flex items-center gap-3">
                                        <x-admin.icon-chip icon="file-contract" :tone="$license->full_text ? 'brand' : 'muted'" />
                                        <div class="min-w-0">
                                            <div class="flex items-center gap-2">
                                                <span class="truncate text-[0.89rem]">{{ $license->name }}</span>
                                                <span class="shrink-0 rounded bg-raised px-1.5 py-0.5 text-[0.68rem] tabular-nums text-paper/45">
                                                    v{{ $license->version }}
                                                </span>
                                            </div>
                                            <div class="truncate text-[0.72rem] text-paper/25">
                                                {{ $license->full_text ? Str::limit($license->summary, 70) : 'No full text yet' }}
                                            </div>
                                        </div>
                                    </div>
                                </td>

                                <td class="px-3 py-3">
                                    <div class="flex flex-wrap gap-1">
                                        @foreach ([
                                            ['Commercial', $license->allows_commercial],
                                            ['Credit', $license->requires_attribution],
                                            ['Edit', $license->allows_derivatives],
                                        ] as [$label, $on])
                                            <span @class([
                                                'rounded-full px-2 py-0.5 text-[0.7rem]',
                                                'bg-brand/15 text-brand' => $on,
                                                'bg-raised text-paper/25' => ! $on,
                                            ])>{{ $label }}</span>
                                        @endforeach
                                    </div>
                                </td>

                                <td class="px-3 py-3 text-right">
                                    <span @class([
                                        'rounded-full px-2.5 py-1 text-[0.75rem] tabular-nums',
                                        'bg-raised text-paper/70' => $license->sounds_count > 0,
                                        'text-paper/20' => $license->sounds_count === 0,
                                    ])>{{ $license->sounds_count }}</span>
                                </td>

                                <td class="px-5 py-3">
                                    <div class="flex items-center justify-end gap-1">
                                        <x-admin.icon-button icon="pen" label="Edit" wire:click="startEdit({{ $license->id }})" />
                                        <x-admin.icon-button icon="trash" label="Delete"
                                                             wire:click="delete({{ $license->id }})"
                                                             wire:confirm="Delete “{{ $license->name }}”?" />
                                    </div>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-5 py-16 text-center">
                                <x-icon name="file-contract" style="regular" class="text-[24px] text-paper/20" />
                                <p class="mt-3 text-[0.9rem] text-paper/45">No licenses yet</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

            @if ($this->licenses->hasPages())
                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-hairline px-5 py-3.5">
                    <span class="text-[0.78rem] text-paper/35">
                        {{ $this->licenses->firstItem() }}–{{ $this->licenses->lastItem() }} of {{ $this->licenses->total() }}
                    </span>

                    <div class="flex items-center gap-1.5">
                        <x-admin.icon-button icon="chevron-left" variant="muted" label="Previous"
                                             wire:click="previousPage" @disabled($this->licenses->onFirstPage()) />

                        @foreach ($this->licenses->getUrlRange(1, $this->licenses->lastPage()) as $page => $url)
                            <button wire:click="gotoPage({{ $page }})" wire:key="lpg-{{ $page }}"
                                    @class([
                                        'grid size-8 place-items-center rounded-full text-[0.78rem] tabular-nums transition duration-200 ease-dbelo',
                                        'bg-brand text-white' => $page === $this->licenses->currentPage(),
                                        'text-paper/40 hover:bg-paper/[0.10] hover:text-paper' => $page !== $this->licenses->currentPage(),
                                    ])>{{ $page }}</button>
                        @endforeach

                        <x-admin.icon-button icon="chevron-right" variant="muted" label="Next"
                                             wire:click="nextPage" @disabled(! $this->licenses->hasMorePages()) />
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
