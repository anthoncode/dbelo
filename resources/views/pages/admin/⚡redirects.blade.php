<?php

use App\Models\ActivityLog;
use App\Models\NotFound;
use App\Models\Post;
use App\Models\Redirect;
use App\Models\Sound;
use App\Services\RedirectResolver;
use App\Support\ReservedSlugs;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.admin')] #[Title('Redirects')] class extends Component {
    use WithPagination;

    #[Url(except: 'redirects')] public string $tab = 'redirects';
    #[Url(as: 'q', except: '')] public string $search = '';
    #[Url(except: false)] public bool $showNoise = false;

    // ── Create ──
    public string $from = '';
    public string $to = '';
    public int $status = 301;
    public string $note = '';

    // ── Inline edit, in the row itself ──
    public ?int $editing = null;
    public string $editTo = '';
    public int $editStatus = 301;
    public string $editNote = '';

    // ── "Turn this 404 into a redirect", in the row itself ──
    public ?int $linking = null;
    public string $linkTo = '';
    public int $linkStatus = 301;
    public ?array $guess = null;

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    /**
     * Only the three properties that change *which* rows are listed reset the
     * pagination. Doing it for every property — the obvious version — jumps
     * you back to page one the moment you pick a status inside an inline form
     * on page three, and takes the row you were editing off the screen.
     */
    public function updated(string $property): void
    {
        if (in_array($property, ['tab', 'search', 'showNoise'], true)) {
            $this->resetPage();
        }
    }

    // ---------------------------------------------------------------
    // Numbers
    // ---------------------------------------------------------------

    #[Computed]
    public function stats(): array
    {
        return [
            [
                'label' => 'Rules',
                'value' => number_format(Redirect::count()),
                'icon' => 'arrow-turn-right',
                'tone' => 'neutral',
                'tab' => 'redirects',
            ],
            [
                'label' => 'Redirected',
                'value' => number_format((int) Redirect::sum('hits')),
                'icon' => 'route',
                'tone' => 'success',
            ],
            [
                'label' => 'Broken links',
                'value' => NotFound::open()->count(),
                'icon' => 'link-slash',
                'tone' => 'danger',
                'tab' => 'not-found',
            ],
            [
                // A rule nobody has ever hit is either premature or wrong,
                // and both are worth noticing before the table has three
                // hundred of them.
                'label' => 'Never fired',
                'value' => Redirect::where('hits', 0)->count(),
                'icon' => 'ghost',
                'tone' => 'warning',
            ],
        ];
    }

    #[Computed]
    public function rules()
    {
        return Redirect::query()
            ->with('author:id,name')
            ->when($this->search, fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('from', 'like', "%{$s}%")
                ->orWhere('to', 'like', "%{$s}%")))
            ->orderByDesc('hits')
            ->orderByDesc('id')
            ->paginate(10);
    }

    #[Computed]
    public function misses()
    {
        return NotFound::query()
            ->where('status', $this->showNoise ? 'noise' : 'open')
            ->when($this->search, fn ($q, $s) => $q->where('path', 'like', "%{$s}%"))
            // Worst first. The path forty people hit is a different problem
            // from the one somebody mistyped once.
            ->orderByDesc('hits')
            ->orderByDesc('last_seen_at')
            ->paginate(10);
    }

    #[Computed]
    public function noiseCount(): int
    {
        return NotFound::noise()->count();
    }

    // ---------------------------------------------------------------
    // Rules
    // ---------------------------------------------------------------

    public function create(): void
    {
        $this->validate([
            'from' => ['required', 'string', 'max:500'],
            'to' => [$this->status === 410 ? 'nullable' : 'required', 'string', 'max:500'],
            'status' => ['required', 'in:301,302,410'],
            'note' => ['nullable', 'string', 'max:300'],
        ], [
            'to.required' => 'Where should it go? Choose “Gone” instead if there is no destination.',
        ]);

        $from = RedirectResolver::normalise($this->from);
        $wildcard = str_ends_with(trim($this->from), '*');

        if ($wildcard) {
            $from = rtrim(RedirectResolver::normalise(rtrim(trim($this->from), '*')), '/').'/*';
        }

        if ($from === '' || $from === '*') {
            $this->addError('from', 'That is the homepage. It cannot be redirected from here.');

            return;
        }

        if (Redirect::where('from', $from)->exists()) {
            $this->addError('from', 'There is already a rule for that path.');

            return;
        }

        // The one failure mode that looks like it worked: a rule for a URL
        // that still returns a page never runs, because redirects only fire
        // on a 404. Better to say so than to let it sit there looking healthy.
        if (! $wildcard && $this->stillLive($from)) {
            $this->addError('from', 'That URL still works, so this rule would never fire. Delete or unpublish it first.');

            return;
        }

        $to = $this->status === 410 ? null : trim($this->to);

        if ($to !== null) {
            if (RedirectResolver::normalise($to) === $from) {
                $this->addError('to', 'That points at itself, which is a loop.');

                return;
            }

            // Never let the table hold a chain: if the destination is itself
            // redirected, jump straight to the end now.
            $to = Redirect::collapse($to);
        }

        $redirect = Redirect::create([
            'from' => $from,
            'to' => $to,
            'status' => $this->status,
            'is_wildcard' => $wildcard,
            'source' => 'manual',
            'note' => $this->note ?: null,
            'created_by' => auth()->id(),
        ]);

        // And repair anything that was already pointing at this path, so
        // yesterday's rule does not quietly become a two-hop journey.
        $repointed = $to ? Redirect::repointTo($from, $to) : 0;

        // If people were already hitting this URL, that inbox item is done.
        NotFound::where('path', $from)->update(['status' => 'fixed', 'redirect_id' => $redirect->id]);

        ActivityLog::record('redirect.created', $redirect, "/{$from} → ".($to ?? 'gone'));

        $this->reset(['from', 'to', 'note']);
        $this->status = 301;
        $this->refresh();

        session()->flash('ok', $repointed
            ? "Rule saved, and {$repointed} older rule(s) now point straight at the new destination."
            : 'Rule saved.');
    }

    public function edit(int $id): void
    {
        $redirect = Redirect::findOrFail($id);

        $this->editing = $id;
        $this->editTo = (string) $redirect->to;
        $this->editStatus = $redirect->status;
        $this->editNote = (string) $redirect->note;
        $this->resetErrorBag();
    }

    public function cancel(): void
    {
        $this->reset(['editing', 'editTo', 'editStatus', 'editNote']);
        $this->resetErrorBag();
    }

    public function update(): void
    {
        $this->validate([
            'editTo' => [$this->editStatus === 410 ? 'nullable' : 'required', 'string', 'max:500'],
            'editStatus' => ['required', 'in:301,302,410'],
            'editNote' => ['nullable', 'string', 'max:300'],
        ]);

        $redirect = Redirect::findOrFail($this->editing);
        $to = $this->editStatus === 410 ? null : trim($this->editTo);

        if ($to !== null && RedirectResolver::normalise($to) === $redirect->from) {
            $this->addError('editTo', 'That points at itself, which is a loop.');

            return;
        }

        // The path itself is never editable: a rule is identified by the URL
        // it repairs, and changing that is a different rule. Delete and
        // create — which also keeps the hit count honest.
        $redirect->update([
            'to' => $to ? Redirect::collapse($to) : null,
            'status' => $this->editStatus,
            'note' => $this->editNote ?: null,
        ]);

        $this->cancel();
        $this->refresh();

        session()->flash('ok', 'Rule updated.');
    }

    public function delete(int $id): void
    {
        $redirect = Redirect::findOrFail($id);

        ActivityLog::record('redirect.deleted', $redirect, "/{$redirect->from}");

        $redirect->delete();
        $this->refresh();

        session()->flash('ok', 'Rule deleted. That URL goes back to a 404.');
    }

    // ---------------------------------------------------------------
    // The inbox
    // ---------------------------------------------------------------

    public function startLink(int $id): void
    {
        $miss = NotFound::findOrFail($id);

        $this->linking = $id;
        $this->linkStatus = 301;
        $this->resetErrorBag();

        // Only computed here, on one row, when somebody actually asked.
        $this->guess = $miss->suggestion();
        $this->linkTo = $this->guess['path'] ?? '';
    }

    public function cancelLink(): void
    {
        $this->reset(['linking', 'linkTo', 'linkStatus', 'guess']);
        $this->resetErrorBag();
    }

    public function link(): void
    {
        $this->validate([
            'linkTo' => [$this->linkStatus === 410 ? 'nullable' : 'required', 'string', 'max:500'],
        ], [
            'linkTo.required' => 'Where should it go?',
        ]);

        $miss = NotFound::findOrFail($this->linking);
        $to = $this->linkStatus === 410 ? null : trim($this->linkTo);

        if ($to !== null && RedirectResolver::normalise($to) === $miss->path) {
            $this->addError('linkTo', 'That points at itself, which is a loop.');

            return;
        }

        $redirect = Redirect::updateOrCreate(
            ['from' => $miss->path],
            [
                'to' => $to ? Redirect::collapse($to) : null,
                'status' => $this->linkStatus,
                'is_wildcard' => false,
                'source' => 'manual',
                'created_by' => auth()->id(),
                'note' => 'Created from '.number_format($miss->hits).' recorded miss(es).',
            ],
        );

        $miss->update(['status' => 'fixed', 'redirect_id' => $redirect->id]);

        ActivityLog::record('redirect.created', $redirect, "/{$miss->path} → ".($to ?? 'gone'));

        $this->cancelLink();
        $this->refresh();

        session()->flash('ok', 'Fixed. Anyone hitting that URL now lands on the right page.');
    }

    public function ignore(int $id): void
    {
        NotFound::whereKey($id)->update(['status' => 'ignored']);
        $this->refresh();
    }

    public function forget(int $id): void
    {
        NotFound::whereKey($id)->delete();
        $this->refresh();
    }

    /**
     * Clear the scanner traffic.
     *
     * Deleting rather than hiding: these rows carry no information beyond
     * "the internet exists", and keeping ten thousand of them makes every
     * query on this table slower for no gain.
     */
    public function purgeNoise(): void
    {
        $count = NotFound::noise()->delete();

        $this->refresh();

        session()->flash('ok', number_format($count).' scanner probe(s) cleared.');
    }

    // ---------------------------------------------------------------

    /**
     * Does this URL still return a page?
     *
     * Not asked of the router: the catch-all page route matches almost any
     * single segment and then 404s from inside the component, so a route
     * match would flag URLs that a rule handles perfectly well. The question
     * that actually matters is whether something is published there.
     */
    protected function stillLive(string $path): bool
    {
        if (! str_contains($path, '/')) {
            if (ReservedSlugs::taken($path)) {
                return true;
            }

            return Post::where('type', 'page')->where('slug', $path)->exists();
        }

        if (str_starts_with($path, 'sounds/')) {
            return Sound::where('slug', substr($path, 7))->exists();
        }

        if (str_starts_with($path, 'blog/')) {
            return Post::where('type', 'post')->where('slug', substr($path, 5))->exists();
        }

        return false;
    }

    protected function refresh(): void
    {
        unset($this->rules, $this->misses, $this->stats, $this->noiseCount);
        cache()->forget('admin.nav.counts');
    }
}; ?>

<div>
    @if (session('ok'))
        <div class="mb-5 flex items-center gap-3 rounded-xl border border-success/30 bg-success/10 px-4 py-3">
            <x-icon name="circle-check" style="solid" class="text-success" />
            <span class="text-[0.88rem]">{{ session('ok') }}</span>
        </div>
    @endif

    {{-- ══════ HEADER ══════ --}}
    <div class="mb-5 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-[1.35rem] font-semibold tracking-[-0.02em]">Redirects</h1>
            <p class="mt-1 max-w-2xl text-[0.83rem] leading-relaxed text-paper/35">
                Rules only run when a URL has already failed, so nothing here can take a working page off the site.
                The second tab is the list of URLs that failed and have no rule yet.
            </p>
        </div>
    </div>

    {{-- ══════ STATS ══════ --}}
    <div class="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($this->stats as $stat)
            @php $isLink = isset($stat['tab']); @endphp

            <button type="button"
                @if ($isLink) wire:click="$set('tab', '{{ $stat['tab'] }}')" @endif
                @class([
                    'rounded-2xl border bg-panel p-5 text-left transition duration-300 ease-dbelo',
                    'border-paper/20' => $isLink && $tab === ($stat['tab'] ?? null),
                    'border-hairline hover:border-paper/15' => $isLink && $tab !== ($stat['tab'] ?? null),
                    'border-hairline cursor-default' => ! $isLink,
                ])>
                <div class="flex items-start justify-between">
                    <span class="text-[0.7rem] uppercase tracking-[0.14em] text-paper/35">{{ $stat['label'] }}</span>
                    <span @class([
                        'grid size-9 place-items-center rounded-full transition duration-200 ease-dbelo',
                        'bg-danger/15 text-danger' => $stat['tone'] === 'danger' && $stat['value'] > 0,
                        'bg-warning/15 text-warning' => $stat['tone'] === 'warning' && $stat['value'] > 0,
                        'bg-success/15 text-success' => $stat['tone'] === 'success',
                        'bg-raised text-paper/40' => $stat['tone'] === 'neutral'
                            || (in_array($stat['tone'], ['danger', 'warning'], true) && ! $stat['value']),
                    ])>
                        <x-icon :name="$stat['icon']" style="solid" class="text-[13px]" />
                    </span>
                </div>
                <div class="mt-3 text-[1.9rem] font-semibold leading-none tracking-[-0.03em]">{{ $stat['value'] }}</div>
            </button>
        @endforeach
    </div>

    {{-- ══════ TABS ══════ --}}
    <div class="mb-5 flex flex-wrap gap-1.5 rounded-2xl border border-hairline bg-panel p-1.5">
        @foreach ([
            'redirects' => ['Redirects', 'arrow-turn-right'],
            'not-found' => ['Not found', 'link-slash'],
        ] as $key => [$label, $icon])
            <button wire:click="$set('tab', '{{ $key }}')"
                    @class([
                        'flex items-center gap-2 rounded-xl px-4 py-2.5 text-[0.84rem] transition duration-200 ease-dbelo',
                        'bg-raised text-paper' => $tab === $key,
                        'text-paper/45 hover:text-paper' => $tab !== $key,
                    ])>
                <x-icon :name="$icon" style="solid" class="text-[11px]" />
                {{ $label }}
            </button>
        @endforeach
    </div>

    @if ($tab === 'redirects')
        {{-- ═══════════════════════════════════════════════════════════
             RULES
             ═══════════════════════════════════════════════════════════ --}}
        <div class="grid gap-5 xl:grid-cols-[340px_1fr]">

            {{-- ══════ COLUMN 1 — ADD ══════ --}}
            <div class="h-fit space-y-5">
                <div class="rounded-2xl border border-hairline bg-panel">
                    <div class="flex items-center gap-3 border-b border-hairline px-5 py-4">
                        <x-admin.icon-chip icon="arrow-turn-right" tone="brand" />
                        <h2 class="text-[0.95rem] font-medium">Add a rule</h2>
                    </div>

                    <form wire:submit="create" class="space-y-4 p-5">
                        <div>
                            <label class="mb-2 block text-[0.72rem] uppercase tracking-[0.14em] text-paper/35">From</label>
                            <div class="flex items-center gap-0 rounded-lg bg-raised pl-3.5 focus-within:ring-2 focus-within:ring-brand/40">
                                <span class="font-mono text-[0.85rem] text-paper/30">/</span>
                                <input type="text" wire:model="from" placeholder="old-page"
                                       class="w-full border-0 bg-transparent px-1.5 py-2.5 font-mono text-[0.85rem] text-paper placeholder:text-paper/25 focus:outline-none focus:ring-0" />
                            </div>
                            @error('from') <p class="mt-1.5 text-[0.8rem] text-danger">{{ $message }}</p> @enderror
                            <p class="mt-1.5 text-[0.75rem] leading-relaxed text-paper/30">
                                End it with <span class="font-mono text-paper/50">/*</span> to catch everything below it —
                                <span class="font-mono text-paper/50">old-blog/*</span> to <span class="font-mono text-paper/50">blog/*</span>
                                moves a whole section and keeps each URL's tail.
                            </p>
                        </div>

                        <div>
                            <label class="mb-2 block text-[0.72rem] uppercase tracking-[0.14em] text-paper/35">Type</label>
                            <div class="grid grid-cols-3 gap-1.5 rounded-lg bg-raised p-1.5">
                                @foreach ([301 => ['Permanent', 'check'], 302 => ['Temporary', 'clock'], 410 => ['Gone', 'ban']] as $code => [$label, $icon])
                                    <button type="button" wire:click="$set('status', {{ $code }})"
                                            @class([
                                                'flex items-center justify-center gap-1.5 rounded-md py-2 text-[0.78rem] transition duration-200 ease-dbelo',
                                                'bg-success/20 text-success' => $status === $code && $code === 301,
                                                'bg-warning/20 text-warning' => $status === $code && $code === 302,
                                                'bg-danger/20 text-danger' => $status === $code && $code === 410,
                                                'text-paper/40 hover:text-paper' => $status !== $code,
                                            ])>
                                        <x-icon :name="$icon" style="solid" class="text-[10px]" />
                                        {{ $label }}
                                    </button>
                                @endforeach
                            </div>
                            <p class="mt-1.5 text-[0.75rem] leading-relaxed text-paper/30">
                                @if ($status === 301)
                                    Search engines move the ranking across and stop asking for the old URL.
                                @elseif ($status === 302)
                                    Nothing is transferred; the old URL stays in the index. Use it while you are still deciding.
                                @else
                                    Nothing to send them to. Google drops the URL far faster than it drops a 404, which is
                                    what you want for anything removed on purpose.
                                @endif
                            </p>
                        </div>

                        @if ($status !== 410)
                            <div>
                                <label class="mb-2 block text-[0.72rem] uppercase tracking-[0.14em] text-paper/35">To</label>
                                <input type="text" wire:model="to" placeholder="sounds/rain-heavy"
                                       class="w-full rounded-lg border-0 bg-raised px-3.5 py-2.5 font-mono text-[0.85rem] text-paper placeholder:text-paper/25 focus:outline-none focus:ring-2 focus:ring-brand/40" />
                                @error('to') <p class="mt-1.5 text-[0.8rem] text-danger">{{ $message }}</p> @enderror
                                <p class="mt-1.5 text-[0.75rem] text-paper/30">
                                    A path on this site, or a full https:// address.
                                </p>
                            </div>
                        @endif

                        <div>
                            <label class="mb-2 block text-[0.72rem] uppercase tracking-[0.14em] text-paper/35">Note</label>
                            <input type="text" wire:model="note" placeholder="Why this exists"
                                   class="w-full rounded-lg border-0 bg-raised px-3.5 py-2.5 text-[0.86rem] text-paper placeholder:text-paper/25 focus:outline-none focus:ring-2 focus:ring-brand/40" />
                            @error('note') <p class="mt-1.5 text-[0.8rem] text-danger">{{ $message }}</p> @enderror
                        </div>

                        <button type="submit"
                                class="flex w-full items-center justify-center gap-2 rounded-lg bg-action py-2.5 text-[0.88rem] font-medium text-white transition duration-200 ease-dbelo hover:brightness-110">
                            <x-icon name="plus" style="solid" class="text-[12px]" />
                            Add rule
                        </button>
                    </form>
                </div>

                <div class="rounded-2xl border border-hairline bg-panel p-5">
                    <div class="flex items-center gap-3">
                        <x-admin.icon-chip icon="shield-check" tone="muted" />
                        <div class="min-w-0 flex-1">
                            <div class="text-[0.88rem]">Safe by construction</div>
                            <div class="text-[0.75rem] text-paper/30">A rule cannot hide a live page</div>
                        </div>
                    </div>

                    <p class="mt-4 text-[0.75rem] leading-relaxed text-paper/30">
                        These are checked only after Laravel has decided that nothing matches the URL. A rule for
                        <span class="font-mono text-paper/50">sounds</span> would never run, because that page exists —
                        so the form refuses to save one and tells you why.
                    </p>
                </div>
            </div>

            {{-- ══════ COLUMN 2 — TABLE ══════ --}}
            <div class="min-w-0 rounded-2xl border border-hairline bg-panel">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-hairline px-5 py-3.5">
                    <h2 class="text-[0.95rem] font-medium">
                        All rules <span class="ml-1.5 text-paper/35">{{ $this->rules->total() }}</span>
                    </h2>

                    <div class="flex items-center gap-2.5 rounded-lg bg-raised px-3 py-2">
                        <x-icon name="magnifying-glass" style="regular" class="text-[12px] text-paper/35" />
                        <input type="search" wire:model.live.debounce.300ms="search" placeholder="Filter…"
                               class="w-32 border-0 bg-transparent p-0 text-[0.83rem] text-paper placeholder:text-paper/30 focus:outline-none focus:ring-0" />
                    </div>
                </div>

                <table class="w-full text-left">
                    <thead>
                        <tr class="border-b border-hairline text-[0.68rem] uppercase tracking-[0.13em] text-paper/30">
                            <th class="w-12 px-5 py-2.5 font-medium">#</th>
                            <th class="px-3 py-2.5 font-medium">Rule</th>
                            <th class="w-28 px-3 py-2.5 font-medium">Type</th>
                            <th class="w-24 px-3 py-2.5 font-medium">Hits</th>
                            <th class="w-[150px] px-5 py-2.5 text-right font-medium">Actions</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-hairline">
                        @php $row = ($this->rules->currentPage() - 1) * $this->rules->perPage(); @endphp

                        @forelse ($this->rules as $rule)
                            @php $row++; @endphp

                            @if ($editing === $rule->id)
                                <tr wire:key="edit-{{ $rule->id }}" class="bg-raised">
                                    <td class="px-5 py-4 text-[0.8rem] tabular-nums text-paper/25">{{ $row }}</td>
                                    <td colspan="4" class="px-3 py-4 pr-5">
                                        <div class="space-y-3">
                                            <div class="flex items-center gap-2 font-mono text-[0.8rem] text-paper/45">
                                                <x-icon name="lock" style="solid" class="text-[10px]" />
                                                /{{ $rule->from }}
                                            </div>

                                            <div class="flex flex-wrap gap-1.5 rounded-lg bg-panel p-1.5">
                                                @foreach ([301 => 'Permanent', 302 => 'Temporary', 410 => 'Gone'] as $code => $label)
                                                    <button type="button" wire:click="$set('editStatus', {{ $code }})"
                                                            @class([
                                                                'rounded-md px-3.5 py-1.5 text-[0.78rem] transition duration-200 ease-dbelo',
                                                                'bg-success/20 text-success' => $editStatus === $code && $code === 301,
                                                                'bg-warning/20 text-warning' => $editStatus === $code && $code === 302,
                                                                'bg-danger/20 text-danger' => $editStatus === $code && $code === 410,
                                                                'text-paper/40 hover:text-paper' => $editStatus !== $code,
                                                            ])>{{ $label }}</button>
                                                @endforeach
                                            </div>

                                            @if ($editStatus !== 410)
                                                <input type="text" wire:model="editTo" wire:keydown.enter="update" placeholder="Destination"
                                                       class="w-full rounded-lg border-0 bg-panel px-3.5 py-2.5 font-mono text-[0.85rem] text-paper focus:outline-none focus:ring-2 focus:ring-brand/40" />
                                                @error('editTo') <p class="text-[0.8rem] text-danger">{{ $message }}</p> @enderror
                                            @endif

                                            <input type="text" wire:model="editNote" placeholder="Note"
                                                   class="w-full rounded-lg border-0 bg-panel px-3.5 py-2.5 text-[0.86rem] text-paper placeholder:text-paper/25 focus:outline-none focus:ring-2 focus:ring-brand/40" />

                                            <div class="flex items-center gap-2">
                                                <button wire:click="update"
                                                        class="flex items-center gap-2 rounded-lg bg-action px-5 py-2.5 text-[0.85rem] font-medium text-white transition hover:brightness-110">
                                                    <x-icon name="check" style="solid" class="text-[11px]" />
                                                    Save
                                                </button>
                                                <button wire:click="cancel"
                                                        class="rounded-lg bg-panel px-4 py-2.5 text-[0.85rem] text-paper/55 transition hover:text-paper">
                                                    Cancel
                                                </button>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            @else
                                <tr wire:key="rule-{{ $rule->id }}" class="transition hover:bg-paper/[0.03]">
                                    <td class="px-5 py-3 text-[0.8rem] tabular-nums text-paper/25">{{ $row }}</td>

                                    <td class="min-w-0 px-3 py-3">
                                        <div class="flex flex-wrap items-center gap-2 font-mono text-[0.8rem]">
                                            <span class="text-paper/70">/{{ $rule->from }}</span>
                                            <x-icon name="arrow-right" style="solid" class="text-[9px] text-paper/25" />
                                            @if ($rule->to)
                                                <span class="truncate text-paper/45">{{ $rule->to }}</span>
                                            @else
                                                <span class="text-danger/70">gone</span>
                                            @endif
                                        </div>

                                        <div class="mt-1 flex flex-wrap items-center gap-2 text-[0.72rem] text-paper/25">
                                            @if ($rule->is_wildcard)
                                                <span class="rounded bg-info/15 px-1.5 py-0.5 text-info">wildcard</span>
                                            @endif
                                            @if ($rule->source !== 'manual')
                                                <span class="rounded bg-raised px-1.5 py-0.5">{{ Redirect::SOURCES[$rule->source] ?? $rule->source }}</span>
                                            @endif
                                            @if ($rule->note)
                                                <span class="truncate">{{ $rule->note }}</span>
                                            @endif
                                        </div>
                                    </td>

                                    <td class="px-3 py-3">
                                        <span @class([
                                            'rounded-full px-2.5 py-1 text-[0.72rem]',
                                            'bg-success/15 text-success' => $rule->status === 301,
                                            'bg-warning/15 text-warning' => $rule->status === 302,
                                            'bg-danger/15 text-danger' => $rule->status === 410,
                                        ])>{{ $rule->status }} · {{ $rule->label() }}</span>
                                    </td>

                                    <td class="px-3 py-3">
                                        <div class="text-[0.82rem] tabular-nums text-paper/60">{{ number_format($rule->hits) }}</div>
                                        @if ($rule->last_hit_at)
                                            <div class="text-[0.7rem] text-paper/25">{{ $rule->last_hit_at->diffForHumans() }}</div>
                                        @else
                                            <div class="text-[0.7rem] text-paper/25">never</div>
                                        @endif
                                    </td>

                                    <td class="px-5 py-3">
                                        <div class="flex items-center justify-end gap-1">
                                            <a href="{{ url('/'.$rule->from) }}" target="_blank" rel="noopener">
                                                <x-admin.icon-button icon="arrow-up-right-from-square" label="Try it" />
                                            </a>

                                            <x-admin.icon-button icon="pen" label="Edit" wire:click="edit({{ $rule->id }})" />

                                            <x-admin.icon-button icon="trash" label="Delete"
                                                                 class="hover:!bg-danger/15 hover:!text-danger"
                                                                 wire:click="delete({{ $rule->id }})" />
                                        </div>
                                    </td>
                                </tr>
                            @endif
                        @empty
                            <tr>
                                <td colspan="5" class="px-5 py-16 text-center">
                                    <x-icon name="arrow-turn-right" style="regular" class="text-[24px] text-paper/20" />
                                    <p class="mt-3 text-[0.9rem] text-paper/45">
                                        {{ $search ? 'Nothing matches that filter' : 'No rules yet' }}
                                    </p>
                                    @unless ($search)
                                        <p class="mx-auto mt-1.5 max-w-sm text-[0.8rem] leading-relaxed text-paper/25">
                                            Most rules are easier to write from the Not found tab, where you can see how
                                            many people are actually hitting each broken URL.
                                        </p>
                                    @endunless
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>

                @if ($this->rules->hasPages())
                    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-hairline px-5 py-3.5">
                        <span class="text-[0.78rem] text-paper/35">
                            {{ $this->rules->firstItem() }}–{{ $this->rules->lastItem() }} of {{ $this->rules->total() }}
                        </span>

                        <div class="flex items-center gap-1.5">
                            <x-admin.icon-button icon="chevron-left" variant="muted" label="Previous"
                                                 wire:click="previousPage" @disabled($this->rules->onFirstPage()) />

                            @foreach ($this->rules->getUrlRange(max(1, $this->rules->currentPage() - 2), min($this->rules->lastPage(), $this->rules->currentPage() + 2)) as $page => $url)
                                <button wire:click="gotoPage({{ $page }})" wire:key="rpg-{{ $page }}"
                                        @class([
                                            'grid size-8 place-items-center rounded-full text-[0.78rem] tabular-nums transition duration-200 ease-dbelo',
                                            'bg-brand text-white' => $page === $this->rules->currentPage(),
                                            'text-paper/40 hover:bg-paper/[0.10] hover:text-paper' => $page !== $this->rules->currentPage(),
                                        ])>{{ $page }}</button>
                            @endforeach

                            <x-admin.icon-button icon="chevron-right" variant="muted" label="Next"
                                                 wire:click="nextPage" @disabled(! $this->rules->hasMorePages()) />
                        </div>
                    </div>
                @endif
            </div>
        </div>

    @else
        {{-- ═══════════════════════════════════════════════════════════
             THE INBOX
             ═══════════════════════════════════════════════════════════ --}}
        <div class="grid gap-5 xl:grid-cols-[340px_1fr]">

            {{-- ══════ COLUMN 1 — WHAT THIS IS ══════ --}}
            <div class="h-fit space-y-5">
                <div class="rounded-2xl border border-hairline bg-panel">
                    <div class="flex items-center gap-3 border-b border-hairline px-5 py-4">
                        <x-admin.icon-chip icon="link-slash" tone="brand" />
                        <h2 class="text-[0.95rem] font-medium">Broken links</h2>
                    </div>

                    <div class="space-y-4 p-5">
                        <p class="text-[0.8rem] leading-relaxed text-paper/40">
                            Every URL somebody asked for and did not get, counted rather than listed one by one.
                            The number beside a path is the thing to read: forty people hitting the same dead URL is a
                            real problem, one person mistyping is not.
                        </p>

                        <div class="rounded-xl bg-raised p-4">
                            <div class="flex items-center gap-2 text-[0.8rem]">
                                <x-icon name="link" style="solid" class="text-[11px] text-info" />
                                <span class="text-paper/60">A referrer means a link</span>
                            </div>
                            <p class="mt-1.5 text-[0.75rem] leading-relaxed text-paper/30">
                                If a row shows where the visitor came from, something out there is still pointing at
                                that URL. Those are worth fixing first — they will keep sending people until you do.
                            </p>
                        </div>

                        <label class="flex cursor-pointer items-start gap-3 rounded-xl bg-raised p-4">
                            <input type="checkbox" wire:model.live="showNoise"
                                   class="mt-0.5 size-4 rounded border-0 bg-panel text-brand focus:ring-2 focus:ring-brand/40" />
                            <span class="min-w-0 flex-1">
                                <span class="block text-[0.83rem]">Show scanner probes</span>
                                <span class="block text-[0.75rem] leading-relaxed text-paper/30">
                                    {{ number_format($this->noiseCount) }} recorded. Bots asking every site on the
                                    internet for /wp-login.php and /.env. Filed separately so this list stays a list of
                                    real problems.
                                </span>
                            </span>
                        </label>

                        @if ($showNoise && $this->noiseCount)
                            <button wire:click="purgeNoise" wire:confirm="Delete all recorded scanner probes?"
                                    class="flex w-full items-center justify-center gap-2 rounded-lg bg-danger/15 py-2.5 text-[0.85rem] text-danger transition duration-200 ease-dbelo hover:bg-danger/25">
                                <x-icon name="broom" style="solid" class="text-[11px]" />
                                Clear {{ number_format($this->noiseCount) }} probe(s)
                            </button>
                        @endif
                    </div>
                </div>
            </div>

            {{-- ══════ COLUMN 2 — TABLE ══════ --}}
            <div class="min-w-0 rounded-2xl border border-hairline bg-panel">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-hairline px-5 py-3.5">
                    <h2 class="text-[0.95rem] font-medium">
                        {{ $showNoise ? 'Scanner probes' : 'Needs a decision' }}
                        <span class="ml-1.5 text-paper/35">{{ $this->misses->total() }}</span>
                    </h2>

                    <div class="flex items-center gap-2.5 rounded-lg bg-raised px-3 py-2">
                        <x-icon name="magnifying-glass" style="regular" class="text-[12px] text-paper/35" />
                        <input type="search" wire:model.live.debounce.300ms="search" placeholder="Filter…"
                               class="w-32 border-0 bg-transparent p-0 text-[0.83rem] text-paper placeholder:text-paper/30 focus:outline-none focus:ring-0" />
                    </div>
                </div>

                <table class="w-full text-left">
                    <thead>
                        <tr class="border-b border-hairline text-[0.68rem] uppercase tracking-[0.13em] text-paper/30">
                            <th class="w-12 px-5 py-2.5 font-medium">#</th>
                            <th class="px-3 py-2.5 font-medium">Path</th>
                            <th class="w-20 px-3 py-2.5 font-medium">Hits</th>
                            <th class="w-32 px-3 py-2.5 font-medium">Last seen</th>
                            <th class="w-[150px] px-5 py-2.5 text-right font-medium">Actions</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-hairline">
                        @php $row = ($this->misses->currentPage() - 1) * $this->misses->perPage(); @endphp

                        @forelse ($this->misses as $miss)
                            @php $row++; @endphp

                            <tr wire:key="miss-{{ $miss->id }}" class="transition hover:bg-paper/[0.03]">
                                <td class="px-5 py-3 text-[0.8rem] tabular-nums text-paper/25">{{ $row }}</td>

                                <td class="min-w-0 px-3 py-3">
                                    <div class="truncate font-mono text-[0.8rem] text-paper/70">/{{ $miss->path }}</div>
                                    @if ($miss->referrer)
                                        <div class="mt-1 flex items-center gap-1.5 text-[0.72rem] text-info/70">
                                            <x-icon name="link" style="solid" class="text-[9px]" />
                                            <span class="truncate">{{ $miss->referrer }}</span>
                                        </div>
                                    @endif
                                </td>

                                <td class="px-3 py-3">
                                    <span @class([
                                        'text-[0.82rem] tabular-nums',
                                        'text-danger' => $miss->hits >= 20,
                                        'text-warning' => $miss->hits >= 5 && $miss->hits < 20,
                                        'text-paper/50' => $miss->hits < 5,
                                    ])>{{ number_format($miss->hits) }}</span>
                                </td>

                                <td class="px-3 py-3 text-[0.78rem] text-paper/35">
                                    {{ $miss->last_seen_at?->diffForHumans() ?? '—' }}
                                </td>

                                <td class="px-5 py-3">
                                    <div class="flex items-center justify-end gap-1">
                                        <x-admin.icon-button icon="arrow-turn-right" variant="brand" label="Create a redirect"
                                                             wire:click="startLink({{ $miss->id }})" />

                                        <x-admin.icon-button icon="eye-slash" label="Ignore" variant="muted"
                                                             wire:click="ignore({{ $miss->id }})" />

                                        <x-admin.icon-button icon="trash" label="Forget"
                                                             class="hover:!bg-danger/15 hover:!text-danger"
                                                             wire:click="forget({{ $miss->id }})" />
                                    </div>
                                </td>
                            </tr>

                            @if ($linking === $miss->id)
                                {{-- The whole point of the screen: the report and the fix are the same row. --}}
                                <tr wire:key="link-{{ $miss->id }}" class="bg-raised">
                                    <td></td>
                                    <td colspan="4" class="px-3 py-4 pr-5">
                                        <div class="space-y-3">
                                            <div class="flex flex-wrap items-center gap-2 font-mono text-[0.8rem] text-paper/45">
                                                /{{ $miss->path }}
                                                <x-icon name="arrow-right" style="solid" class="text-[9px] text-paper/25" />
                                            </div>

                                            @if ($guess)
                                                <div class="flex items-center gap-2.5 rounded-lg bg-info/10 px-3.5 py-2.5 text-[0.78rem]">
                                                    <x-icon name="lightbulb" style="solid" class="text-[11px] text-info" />
                                                    <span class="text-paper/55">
                                                        Closest match in the catalogue — {{ $guess['kind'] }},
                                                        {{ $guess['score'] }}% similar. Change it if that is not what they meant.
                                                    </span>
                                                </div>
                                            @endif

                                            <div class="flex flex-wrap gap-1.5 rounded-lg bg-panel p-1.5">
                                                @foreach ([301 => 'Permanent', 302 => 'Temporary', 410 => 'Gone'] as $code => $label)
                                                    <button type="button" wire:click="$set('linkStatus', {{ $code }})"
                                                            @class([
                                                                'rounded-md px-3.5 py-1.5 text-[0.78rem] transition duration-200 ease-dbelo',
                                                                'bg-success/20 text-success' => $linkStatus === $code && $code === 301,
                                                                'bg-warning/20 text-warning' => $linkStatus === $code && $code === 302,
                                                                'bg-danger/20 text-danger' => $linkStatus === $code && $code === 410,
                                                                'text-paper/40 hover:text-paper' => $linkStatus !== $code,
                                                            ])>{{ $label }}</button>
                                                @endforeach
                                            </div>

                                            @if ($linkStatus !== 410)
                                                <input type="text" wire:model="linkTo" autofocus wire:keydown.enter="link"
                                                       placeholder="sounds/rain-heavy"
                                                       class="w-full rounded-lg border-0 bg-panel px-3.5 py-2.5 font-mono text-[0.85rem] text-paper placeholder:text-paper/25 focus:outline-none focus:ring-2 focus:ring-brand/40" />
                                                @error('linkTo') <p class="text-[0.8rem] text-danger">{{ $message }}</p> @enderror
                                            @endif

                                            <div class="flex items-center gap-2">
                                                <button wire:click="link"
                                                        class="flex items-center gap-2 rounded-lg bg-action px-5 py-2.5 text-[0.85rem] font-medium text-white transition hover:brightness-110">
                                                    <x-icon name="check" style="solid" class="text-[11px]" />
                                                    Create rule
                                                </button>
                                                <button wire:click="cancelLink"
                                                        class="rounded-lg bg-panel px-4 py-2.5 text-[0.85rem] text-paper/55 transition hover:text-paper">
                                                    Cancel
                                                </button>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            @endif
                        @empty
                            <tr>
                                <td colspan="5" class="px-5 py-16 text-center">
                                    <x-icon name="circle-check" style="regular" class="text-[24px] text-success/40" />
                                    <p class="mt-3 text-[0.9rem] text-paper/45">
                                        {{ $search ? 'Nothing matches that filter' : 'Nothing broken' }}
                                    </p>
                                    @unless ($search)
                                        <p class="mx-auto mt-1.5 max-w-sm text-[0.8rem] leading-relaxed text-paper/25">
                                            Every URL anyone has asked for either exists or already has a rule.
                                        </p>
                                    @endunless
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>

                @if ($this->misses->hasPages())
                    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-hairline px-5 py-3.5">
                        <span class="text-[0.78rem] text-paper/35">
                            {{ $this->misses->firstItem() }}–{{ $this->misses->lastItem() }} of {{ $this->misses->total() }}
                        </span>

                        <div class="flex items-center gap-1.5">
                            <x-admin.icon-button icon="chevron-left" variant="muted" label="Previous"
                                                 wire:click="previousPage" @disabled($this->misses->onFirstPage()) />

                            @foreach ($this->misses->getUrlRange(max(1, $this->misses->currentPage() - 2), min($this->misses->lastPage(), $this->misses->currentPage() + 2)) as $page => $url)
                                <button wire:click="gotoPage({{ $page }})" wire:key="mpg-{{ $page }}"
                                        @class([
                                            'grid size-8 place-items-center rounded-full text-[0.78rem] tabular-nums transition duration-200 ease-dbelo',
                                            'bg-brand text-white' => $page === $this->misses->currentPage(),
                                            'text-paper/40 hover:bg-paper/[0.10] hover:text-paper' => $page !== $this->misses->currentPage(),
                                        ])>{{ $page }}</button>
                            @endforeach

                            <x-admin.icon-button icon="chevron-right" variant="muted" label="Next"
                                                 wire:click="nextPage" @disabled(! $this->misses->hasMorePages()) />
                        </div>
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
