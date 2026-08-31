<?php

use App\Models\ActivityLog;
use App\Models\Claim;
use App\Models\Redirect;
use App\Models\Sound;
use App\Notifications\ClaimFiled;
use App\Services\RedirectResolver;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.admin')] #[Title('Claims')] class extends Component {
    use WithPagination;

    #[Url(except: 'open')] public string $filter = 'open';
    #[Url(as: 'q', except: '')] public string $search = '';

    /** Which claim is expanded. Only one at a time: these need reading. */
    public ?int $open = null;

    /** Resolution flow. */
    public ?int $deciding = null;
    public string $decision = '';
    public string $notes = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    public function updated(string $property): void
    {
        if ($property !== 'page') {
            $this->resetPage();
        }
    }

    #[Computed]
    public function stats(): array
    {
        return [
            ['label' => 'New', 'value' => Claim::where('status', Claim::STATUS_NEW)->count(), 'icon' => 'inbox', 'tone' => 'danger'],
            ['label' => 'Reviewing', 'value' => Claim::where('status', Claim::STATUS_REVIEWING)->count(), 'icon' => 'magnifying-glass', 'tone' => 'warning'],
            ['label' => 'Offline now', 'value' => Sound::underClaim()->count(), 'icon' => 'eye-slash', 'tone' => 'info'],
            ['label' => 'Accepted', 'value' => Claim::where('status', Claim::STATUS_ACCEPTED)->count(), 'icon' => 'gavel', 'tone' => 'neutral'],
            ['label' => 'Rejected', 'value' => Claim::where('status', Claim::STATUS_REJECTED)->count(), 'icon' => 'shield-check', 'tone' => 'success'],
        ];
    }

    #[Computed]
    public function claims()
    {
        return Claim::query()
            ->with(['sound:id,title,slug,status,source,original_author,source_url,downloads_count,user_id,deleted_at', 'contributor:id,name,email,role', 'resolver:id,name'])
            ->when($this->filter === 'open', fn ($q) => $q->open())
            ->when(in_array($this->filter, ['new', 'reviewing', 'accepted', 'rejected'], true),
                fn ($q) => $q->where('status', $this->filter))
            ->when($this->search, fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('claimant_name', 'like', "%{$s}%")
                ->orWhere('claimant_email', 'like', "%{$s}%")
                ->orWhere('claimant_organisation', 'like', "%{$s}%")
                ->orWhereHas('sound', fn ($x) => $x->where('title', 'like', "%{$s}%"))))
            // Oldest open claim first: a complaint that has been sitting for
            // three weeks is the problem, not the one that arrived today.
            ->orderByRaw("FIELD(status, 'new', 'reviewing', 'accepted', 'rejected', 'withdrawn')")
            ->oldest('created_at')
            ->paginate(10);
    }

    public function toggle(int $id): void
    {
        $this->open = $this->open === $id ? null : $id;
        $this->cancelDecision();
    }

    // ── Taking the sound offline ──────────────────────────────────────
    // This is what the Terms promise: review, and where the claim is
    // credible, take it down WHILE investigating. Separate from deciding.

    public function takeDown(int $id): void
    {
        $claim = Claim::with('sound')->findOrFail($id);
        $sound = $claim->sound;

        if (! $sound || $sound->isUnderClaim()) {
            return;
        }

        $sound->forceFill(['status' => Sound::STATUS_CLAIMED])->save();

        $claim->forceFill([
            'sound_taken_down_at' => now(),
            'status' => $claim->status === Claim::STATUS_NEW ? Claim::STATUS_REVIEWING : $claim->status,
        ])->save();

        ActivityLog::record('claim.sound.taken_down', $claim,
            "{$claim->reference()}: {$sound->title} taken offline");

        $this->refresh();
        session()->flash('ok', "{$sound->title} is offline. Its page shows a notice.");
    }

    public function putBack(int $id): void
    {
        $claim = Claim::with('sound')->findOrFail($id);
        $sound = $claim->sound;

        if (! $sound || ! $sound->isUnderClaim()) {
            return;
        }

        $sound->forceFill(['status' => Sound::STATUS_PUBLISHED])->save();
        $claim->forceFill(['sound_taken_down_at' => null])->save();

        ActivityLog::record('claim.sound.restored', $claim,
            "{$claim->reference()}: {$sound->title} back online");

        $this->refresh();
        session()->flash('ok', "{$sound->title} is back in the catalogue.");
    }

    public function markReviewing(int $id): void
    {
        $claim = Claim::findOrFail($id);
        $claim->forceFill(['status' => Claim::STATUS_REVIEWING])->save();

        ActivityLog::record('claim.reviewing', $claim, "{$claim->reference()} under review");

        $this->refresh();
    }

    public function notifyContributor(int $id): void
    {
        $claim = Claim::with(['sound', 'contributor'])->findOrFail($id);

        if (! $claim->contributor) {
            session()->flash('error', 'This sound has no contributor to notify.');

            return;
        }

        $claim->contributor->notify(new ClaimFiled($claim));

        $claim->forceFill(['contributor_notified_at' => now()])->save();

        ActivityLog::record('claim.contributor.notified', $claim,
            "{$claim->reference()}: {$claim->contributor->name} notified");

        $this->refresh();
        session()->flash('ok', "{$claim->contributor->name} has been emailed.");
    }

    // ── Deciding ──────────────────────────────────────────────────────

    public function startDecision(int $id, string $decision): void
    {
        $this->deciding = $id;
        $this->decision = $decision;
        $this->notes = '';
    }

    public function cancelDecision(): void
    {
        $this->reset(['deciding', 'decision', 'notes']);
        $this->resetErrorBag();
    }

    public function decide(int $id): void
    {
        $this->validate(['notes' => ['required', 'string', 'min:10', 'max:1000']], [
            'notes.required' => 'Write down why. In six months this note is the only record of the reasoning.',
        ]);

        $claim = Claim::with('sound')->findOrFail($id);
        $sound = $claim->sound;
        $accepted = $this->decision === 'accept';

        $claim->forceFill([
            'status' => $accepted ? Claim::STATUS_ACCEPTED : Claim::STATUS_REJECTED,
            'resolved_by' => auth()->id(),
            'resolved_at' => now(),
            'resolution_notes' => $this->notes,
        ])->save();

        if ($sound) {
            if ($accepted) {
                // Claim upheld: the sound leaves the catalogue for good. The
                // files stay on disk — a soft delete, not a force delete —
                // because the download history still points at them.
                $sound->forceFill(['status' => Sound::STATUS_CLAIMED])->save();
                $sound->delete();

                // And the URL stops being a 404.
                //
                // 410 Gone rather than a redirect: there is nowhere honest to
                // send these people, and Google retires a 410 far faster than
                // a 404 — which is exactly what you want when the reason for
                // removal is legal. Without this the page stays in the index
                // for months, still advertising a sound we were told to pull.
                Redirect::updateOrCreate(
                    ['from' => RedirectResolver::normalise("sounds/{$sound->slug}")],
                    [
                        'to' => null,
                        'status' => 410,
                        'is_wildcard' => false,
                        'source' => 'claim',
                        'note' => "Removed after {$claim->reference()}.",
                        'created_by' => auth()->id(),
                    ],
                );
            } else {
                $sound->forceFill(['status' => Sound::STATUS_PUBLISHED])->save();
                $claim->forceFill(['sound_taken_down_at' => null])->save();

                // Claim rejected: the sound is back, so the Gone rule has to
                // go with it or the URL stays dead while the record is live.
                //
                // Fetched and deleted one by one on purpose: a mass delete on
                // the query builder skips model events, and it is those events
                // that drop the cached redirect map. Without them the rule is
                // gone from the table and still live on the site.
                Redirect::where('from', RedirectResolver::normalise("sounds/{$sound->slug}"))
                    ->where('source', 'claim')
                    ->get()
                    ->each->delete();
            }
        }

        ActivityLog::record($accepted ? 'claim.accepted' : 'claim.rejected', $claim,
            "{$claim->reference()} ".($accepted ? 'accepted' : 'rejected'),
            ['notes' => $this->notes]);

        $this->cancelDecision();
        $this->refresh();

        session()->flash('ok', $accepted
            ? 'Claim accepted. The sound is out of the catalogue and its URL now answers 410 Gone.'
            : 'Claim rejected. The sound is back online.');
    }

    protected function refresh(): void
    {
        unset($this->claims, $this->stats);
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

    @if (session('error'))
        <div class="mb-5 flex items-center gap-3 rounded-xl border border-danger/30 bg-danger/10 px-4 py-3">
            <x-icon name="triangle-exclamation" style="solid" class="text-danger" />
            <span class="text-[0.88rem]">{{ session('error') }}</span>
        </div>
    @endif

    {{-- ══════ STATS ══════ --}}
    <div class="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
        @foreach ($this->stats as $stat)
            <div class="rounded-2xl border border-hairline bg-panel p-5">
                <div class="flex items-start justify-between">
                    <span class="text-[0.7rem] uppercase tracking-[0.14em] text-paper/35">{{ $stat['label'] }}</span>
                    <span @class([
                        'grid size-9 place-items-center rounded-full',
                        'bg-danger/15 text-danger' => $stat['tone'] === 'danger' && $stat['value'] > 0,
                        'bg-warning/15 text-warning' => $stat['tone'] === 'warning' && $stat['value'] > 0,
                        'bg-info/15 text-info' => $stat['tone'] === 'info' && $stat['value'] > 0,
                        'bg-success/15 text-success' => $stat['tone'] === 'success',
                        'bg-raised text-paper/40' => $stat['tone'] === 'neutral' || (! $stat['value'] && $stat['tone'] !== 'success'),
                    ])>
                        <x-icon :name="$stat['icon']" style="solid" class="text-[13px]" />
                    </span>
                </div>
                <div class="mt-3 text-[1.9rem] font-semibold leading-none tracking-[-0.03em]">{{ $stat['value'] }}</div>
            </div>
        @endforeach
    </div>

    <div class="rounded-2xl border border-hairline bg-panel">

        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-hairline px-5 py-3.5">
            <div class="flex flex-wrap gap-1.5">
                @foreach (['open' => 'Open', 'new' => 'New', 'reviewing' => 'Reviewing', 'accepted' => 'Accepted', 'rejected' => 'Rejected', 'all' => 'All'] as $key => $label)
                    <button wire:click="$set('filter', '{{ $key }}')"
                            @class([
                                'rounded-lg px-3.5 py-2 text-[0.82rem] transition duration-200 ease-dbelo',
                                'bg-raised text-paper' => $filter === $key,
                                'text-paper/45 hover:text-paper' => $filter !== $key,
                            ])>{{ $label }}</button>
                @endforeach
            </div>

            <div class="flex items-center gap-2.5 rounded-lg bg-raised px-3 py-2">
                <x-icon name="magnifying-glass" style="regular" class="text-[12px] text-paper/35" />
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="Sound or claimant…"
                       class="w-48 border-0 bg-transparent p-0 text-[0.83rem] text-paper placeholder:text-paper/30 focus:outline-none focus:ring-0" />
            </div>
        </div>

        <div class="divide-y divide-hairline">
            @forelse ($this->claims as $claim)
                @php
                    $sound = $claim->sound;
                    $isOpen = $this->open === $claim->id;
                    $offline = $sound?->isUnderClaim();
                @endphp

                <div wire:key="claim-{{ $claim->id }}">

                    {{-- ── Collapsed row ── --}}
                    <button wire:click="toggle({{ $claim->id }})"
                            class="flex w-full items-center gap-4 px-5 py-4 text-left transition hover:bg-paper/[0.03]">

                        <span @class([
                            'grid size-9 shrink-0 place-items-center rounded-full',
                            'bg-danger/15 text-danger' => $claim->status === 'new',
                            'bg-warning/15 text-warning' => $claim->status === 'reviewing',
                            'bg-raised text-paper/35' => ! $claim->isOpen(),
                        ])>
                            <x-icon name="shield-exclamation" style="solid" class="text-[12px]" />
                        </span>

                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="truncate text-[0.9rem]">{{ $sound?->title ?? 'Sound removed' }}</span>
                                <span class="font-mono text-[0.7rem] text-paper/25">{{ $claim->reference() }}</span>
                                @if ($offline)
                                    <span class="rounded-full bg-info/15 px-2 py-0.5 text-[0.68rem] text-info">Offline</span>
                                @endif
                            </div>
                            <div class="truncate text-[0.75rem] text-paper/30">
                                {{ $claim->rightLabel() }} · {{ $claim->claimant_organisation ?: $claim->claimant_name }}
                                · {{ $claim->created_at->diffForHumans(short: true) }}
                                @if ($claim->downloads_at_claim)
                                    · <span class="text-warning/80">{{ number_format($claim->downloads_at_claim) }} already downloaded</span>
                                @endif
                            </div>
                        </div>

                        <span @class([
                            'shrink-0 rounded-full px-2.5 py-1 text-[0.72rem] capitalize',
                            'bg-danger/15 text-danger' => $claim->status === 'new',
                            'bg-warning/15 text-warning' => $claim->status === 'reviewing',
                            'bg-raised text-paper/45' => $claim->status === 'accepted',
                            'bg-success/15 text-success' => $claim->status === 'rejected',
                        ])>{{ $claim->status }}</span>

                        <x-icon :name="$isOpen ? 'chevron-up' : 'chevron-down'" style="solid" class="shrink-0 text-[11px] text-paper/25" />
                    </button>

                    {{-- ── Expanded ── --}}
                    @if ($isOpen)
                        <div class="bg-canvas/40 px-5 pb-5">
                            <div class="grid gap-5 lg:grid-cols-2">

                                {{-- The sound side --}}
                                <div class="rounded-xl bg-panel p-5">
                                    <div class="micro mb-3 text-[0.7rem] uppercase tracking-[0.14em] text-paper/35">The sound</div>

                                    @if ($sound)
                                        <a href="{{ route('sounds.show', $sound) }}" target="_blank"
                                           class="text-[0.95rem] transition hover:text-brand">{{ $sound->title }}</a>

                                        <dl class="mt-4 divide-y divide-hairline text-[0.82rem]">
                                            <div class="flex justify-between gap-3 py-2.5">
                                                <dt class="text-paper/35">Uploaded by</dt>
                                                <dd class="text-right">
                                                    @if ($claim->contributor)
                                                        <a href="{{ route('admin.users.show', $claim->contributor) }}" wire:navigate class="hover:text-brand">
                                                            {{ $claim->contributor->name }}
                                                        </a>
                                                    @else
                                                        <span class="text-paper/25">—</span>
                                                    @endif
                                                </dd>
                                            </div>

                                            {{-- The single most useful field on this screen. The
                                                 Contributor Agreement §4 makes declaring third-party
                                                 material mandatory, so what they wrote here decides
                                                 most claims. --}}
                                            <div class="flex justify-between gap-3 py-2.5">
                                                <dt class="text-paper/35">Declared origin</dt>
                                                <dd @class([
                                                    'text-right capitalize',
                                                    'text-warning' => $sound->source !== 'original',
                                                ])>{{ str_replace('_', ' ', $sound->source) }}</dd>
                                            </div>

                                            @if ($sound->original_author)
                                                <div class="flex justify-between gap-3 py-2.5">
                                                    <dt class="text-paper/35">Original author</dt>
                                                    <dd class="text-right">{{ $sound->original_author }}</dd>
                                                </div>
                                            @endif

                                            @if ($sound->source_url)
                                                <div class="flex justify-between gap-3 py-2.5">
                                                    <dt class="text-paper/35">Source</dt>
                                                    <dd class="truncate text-right">
                                                        <a href="{{ $sound->source_url }}" target="_blank" rel="noopener" class="text-info hover:underline">
                                                            {{ Str::limit($sound->source_url, 34) }}
                                                        </a>
                                                    </dd>
                                                </div>
                                            @endif

                                            <div class="flex justify-between gap-3 py-2.5">
                                                <dt class="text-paper/35">Downloads when claimed</dt>
                                                <dd class="text-right font-semibold text-warning">{{ number_format($claim->downloads_at_claim) }}</dd>
                                            </div>
                                        </dl>

                                        @if ($claim->downloads_at_claim > 0)
                                            <p class="mt-3 rounded-lg bg-warning/10 px-3.5 py-2.5 text-[0.76rem] leading-relaxed text-paper/60">
                                                Those {{ number_format($claim->downloads_at_claim) }} licences were granted and
                                                are not revoked by anything on this screen. Taking the sound offline stops new
                                                downloads — it does not reach work already published.
                                            </p>
                                        @endif
                                    @else
                                        <p class="text-[0.85rem] text-paper/35">The sound no longer exists.</p>
                                    @endif
                                </div>

                                {{-- The claimant side --}}
                                <div class="rounded-xl bg-panel p-5">
                                    <div class="micro mb-3 text-[0.7rem] uppercase tracking-[0.14em] text-paper/35">The claim</div>

                                    <dl class="divide-y divide-hairline text-[0.82rem]">
                                        <div class="flex justify-between gap-3 py-2.5">
                                            <dt class="text-paper/35">Claimant</dt>
                                            <dd class="text-right">{{ $claim->claimant_name }}</dd>
                                        </div>
                                        <div class="flex justify-between gap-3 py-2.5">
                                            <dt class="text-paper/35">Email</dt>
                                            <dd class="truncate text-right">
                                                <a href="mailto:{{ $claim->claimant_email }}" class="text-info hover:underline">{{ $claim->claimant_email }}</a>
                                            </dd>
                                        </div>
                                        @if ($claim->claimant_organisation)
                                            <div class="flex justify-between gap-3 py-2.5">
                                                <dt class="text-paper/35">Organisation</dt>
                                                <dd class="text-right">{{ $claim->claimant_organisation }}</dd>
                                            </div>
                                        @endif
                                        <div class="flex justify-between gap-3 py-2.5">
                                            <dt class="text-paper/35">Acting as</dt>
                                            <dd class="text-right">{{ $claim->claimant_role === 'agent' ? 'Agent' : 'Rights holder' }}</dd>
                                        </div>
                                        <div class="flex justify-between gap-3 py-2.5">
                                            <dt class="text-paper/35">Sworn statement</dt>
                                            <dd @class(['text-right', 'text-success' => $claim->sworn, 'text-danger' => ! $claim->sworn])>
                                                {{ $claim->sworn ? 'Signed' : 'Not signed' }}
                                            </dd>
                                        </div>
                                        @if ($claim->evidence_url)
                                            <div class="flex justify-between gap-3 py-2.5">
                                                <dt class="text-paper/35">Evidence</dt>
                                                <dd class="truncate text-right">
                                                    <a href="{{ $claim->evidence_url }}" target="_blank" rel="noopener" class="text-info hover:underline">
                                                        {{ Str::limit($claim->evidence_url, 34) }}
                                                    </a>
                                                </dd>
                                            </div>
                                        @endif
                                    </dl>

                                    <p class="mt-4 whitespace-pre-line rounded-lg bg-raised px-3.5 py-3 text-[0.82rem] leading-relaxed text-paper/70">{{ $claim->description }}</p>

                                    @if ($claim->claimant_address)
                                        <p class="mt-2 whitespace-pre-line text-[0.75rem] text-paper/30">{{ $claim->claimant_address }}</p>
                                    @endif
                                </div>
                            </div>

                            {{-- Resolution record --}}
                            @if (! $claim->isOpen())
                                <div class="mt-5 rounded-xl bg-panel p-5">
                                    <div class="text-[0.7rem] uppercase tracking-[0.14em] text-paper/35">
                                        {{ ucfirst($claim->status) }} by {{ $claim->resolver?->name ?? 'system' }} · {{ $claim->resolved_at?->format('M j, Y') }}
                                    </div>
                                    <p class="mt-2 whitespace-pre-line text-[0.85rem] leading-relaxed text-paper/70">{{ $claim->resolution_notes }}</p>
                                </div>
                            @endif

                            {{-- Actions --}}
                            @if ($claim->isOpen())
                                @if ($deciding === $claim->id)
                                    <div class="mt-5 rounded-xl bg-panel p-5">
                                        <span class="mb-2 block text-[0.7rem] uppercase tracking-[0.14em] text-paper/35">
                                            {{ $decision === 'accept' ? 'Why are you accepting this claim?' : 'Why are you rejecting it?' }}
                                        </span>

                                        @if ($decision === 'accept')
                                            <p class="mb-3 text-[0.78rem] leading-relaxed text-paper/45">
                                                The sound leaves the catalogue permanently. Its files stay on disk because the
                                                download history points at them, and the licences already granted stand.
                                            </p>
                                        @endif

                                        <textarea wire:model="notes" rows="3" autofocus
                                                  placeholder="What convinced you. Include what the contributor said, if you asked them."
                                                  class="w-full resize-none rounded-lg border-0 bg-raised px-3.5 py-2.5 text-[0.88rem] text-paper placeholder:text-paper/25 focus:outline-none focus:ring-2 focus:ring-brand/40"></textarea>
                                        @error('notes') <p class="mt-1.5 text-[0.8rem] text-danger">{{ $message }}</p> @enderror

                                        <div class="mt-3 flex flex-wrap gap-2">
                                            <button wire:click="decide({{ $claim->id }})" @class([
                                                'rounded-lg px-5 py-2.5 text-[0.85rem] font-medium text-white transition hover:brightness-110',
                                                'bg-danger' => $decision === 'accept',
                                                'bg-success !text-ink' => $decision !== 'accept',
                                            ])>
                                                {{ $decision === 'accept' ? 'Accept and remove the sound' : 'Reject and republish' }}
                                            </button>
                                            <button wire:click="cancelDecision" class="rounded-lg bg-raised px-4 py-2.5 text-[0.85rem] text-paper/55 transition hover:text-paper">Cancel</button>
                                        </div>
                                    </div>
                                @else
                                    <div class="mt-5 flex flex-wrap items-center gap-2">
                                        @if ($sound && ! $offline)
                                            <button wire:click="takeDown({{ $claim->id }})"
                                                    class="flex items-center gap-2 rounded-lg bg-action px-4 py-2.5 text-[0.83rem] font-medium text-white transition hover:brightness-110">
                                                <x-icon name="eye-slash" style="solid" class="text-[11px]" />
                                                Take offline now
                                            </button>
                                        @elseif ($sound && $offline)
                                            <button wire:click="putBack({{ $claim->id }})"
                                                    class="flex items-center gap-2 rounded-lg bg-raised px-4 py-2.5 text-[0.83rem] text-paper/70 transition hover:text-paper">
                                                <x-icon name="eye" style="solid" class="text-[11px]" />
                                                Put back online
                                            </button>
                                        @endif

                                        @if ($claim->status === 'new')
                                            <button wire:click="markReviewing({{ $claim->id }})"
                                                    class="rounded-lg bg-raised px-4 py-2.5 text-[0.83rem] text-paper/70 transition hover:text-paper">
                                                Mark as reviewing
                                            </button>
                                        @endif

                                        @if ($claim->contributor)
                                            <button wire:click="notifyContributor({{ $claim->id }})"
                                                    @class([
                                                        'flex items-center gap-2 rounded-lg px-4 py-2.5 text-[0.83rem] transition',
                                                        'bg-info/15 text-info hover:bg-info/25' => ! $claim->contributor_notified_at,
                                                        'bg-raised text-paper/35' => $claim->contributor_notified_at,
                                                    ])>
                                                <x-icon name="paper-plane" style="solid" class="text-[11px]" />
                                                {{ $claim->contributor_notified_at
                                                    ? 'Notified '.$claim->contributor_notified_at->diffForHumans(short: true)
                                                    : 'Notify contributor' }}
                                            </button>
                                        @endif

                                        <span class="ml-auto flex gap-2">
                                            <button wire:click="startDecision({{ $claim->id }}, 'reject')"
                                                    class="rounded-lg bg-success/15 px-4 py-2.5 text-[0.83rem] text-success transition hover:bg-success/25">
                                                Reject claim
                                            </button>
                                            <button wire:click="startDecision({{ $claim->id }}, 'accept')"
                                                    class="rounded-lg bg-danger/15 px-4 py-2.5 text-[0.83rem] text-danger transition hover:bg-danger/25">
                                                Accept claim
                                            </button>
                                        </span>
                                    </div>
                                @endif
                            @endif
                        </div>
                    @endif
                </div>
            @empty
                <div class="px-5 py-16 text-center">
                    <x-icon name="shield-check" style="regular" class="text-[24px] text-paper/20" />
                    <p class="mt-3 text-[0.9rem] text-paper/45">
                        {{ $filter === 'open' ? 'Nothing to answer. Quiet is the right state for this screen.' : 'No claims here.' }}
                    </p>
                </div>
            @endforelse
        </div>

        @if ($this->claims->hasPages())
            <div class="flex items-center justify-between gap-3 border-t border-hairline px-5 py-3.5">
                <span class="text-[0.78rem] text-paper/35">
                    {{ $this->claims->firstItem() }}–{{ $this->claims->lastItem() }} of {{ $this->claims->total() }}
                </span>
                <div class="flex items-center gap-1.5">
                    <x-admin.icon-button icon="chevron-left" variant="muted" label="Previous"
                                         wire:click="previousPage" @disabled($this->claims->onFirstPage()) />
                    <x-admin.icon-button icon="chevron-right" variant="muted" label="Next"
                                         wire:click="nextPage" @disabled(! $this->claims->hasMorePages()) />
                </div>
            </div>
        @endif
    </div>
</div>
