<?php

use App\Models\Campaign;
use App\Models\Subscriber;
use App\Services\CampaignSender;
use App\Services\DigestBuilder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.admin')] #[Title('Subscribers')] class extends Component {
    use WithPagination;

    #[Url(as: 'q', except: '')] public string $search = '';
    #[Url(except: 'subscribed')] public string $status = 'subscribed';

    // ── Add by hand ──
    public string $email = '';
    public string $name = '';

    // ── Digest settings ──
    public bool $digestActive = false;
    public int $digestDay = 4;
    public string $digestTime = '10:00';
    public array $digestBlocks = [];
    public string $digestSubject = '';
    public string $digestPreheader = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $digest = Campaign::digest();

        $this->digestActive = $digest->is_active;
        $this->digestDay = $digest->schedule_day ?? 4;
        $this->digestTime = substr((string) $digest->schedule_time, 0, 5) ?: '10:00';
        $this->digestBlocks = $digest->blocks ?? ['sounds', 'packs', 'post'];
        $this->digestSubject = $digest->subject;
        $this->digestPreheader = (string) $digest->preheader;
    }

    public function updated(string $property): void
    {
        if ($property !== 'page') {
            $this->resetPage();
        }
    }

    #[Computed]
    public function digest(): Campaign
    {
        return Campaign::digest();
    }

    #[Computed]
    public function stats(): array
    {
        $active = Subscriber::active()->count();
        $total = Subscriber::count();

        return [
            ['label' => 'Subscribed', 'value' => number_format($active), 'icon' => 'envelope', 'tone' => 'success'],
            ['label' => 'Only sounds', 'value' => Subscriber::active()->where('wants_promos', false)->count(), 'icon' => 'waveform-lines', 'tone' => 'info'],
            ['label' => 'Unsubscribed', 'value' => Subscriber::where('status', 'unsubscribed')->count(), 'icon' => 'envelope-open', 'tone' => 'neutral'],
            // Above roughly 0.5% something is wrong with what you are sending.
            ['label' => 'Opt-out rate', 'value' => $total ? round(($total - $active) / $total * 100, 1).'%' : '—', 'icon' => 'arrow-trend-down',
             'tone' => $total && ($total - $active) / $total > 0.05 ? 'danger' : 'neutral'],
            ['label' => 'Next digest', 'value' => $this->digest->is_active ? $this->nextRun() : 'Off', 'icon' => 'calendar-days',
             'tone' => $this->digest->is_active ? 'brand' : 'neutral'],
        ];
    }

    protected function nextRun(): string
    {
        return now()->next($this->digest->schedule_day ?? 4)->format('D j M');
    }

    #[Computed]
    public function subscribers()
    {
        return Subscriber::query()
            ->with('user:id,name,role')
            ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))
            ->when($this->search, fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('email', 'like', "%{$s}%")
                ->orWhere('name', 'like', "%{$s}%")))
            ->latest('subscribed_at')
            ->paginate(12);
    }

    #[Computed]
    public function preview(): array
    {
        return app(DigestBuilder::class)->build($this->digest);
    }

    #[Computed]
    public function reach(): int
    {
        return app(CampaignSender::class)->count($this->digest);
    }

    // ---------------------------------------------------------------

    public function add(): void
    {
        $this->validate([
            'email' => ['required', 'email', 'max:255', 'unique:subscribers,email'],
            'name' => ['nullable', 'string', 'max:120'],
        ]);

        Subscriber::add($this->email, [
            'name' => $this->name ?: null,
            'source' => 'admin',
            'consent_text' => 'Added by an administrator.',
        ]);

        $this->reset(['email', 'name']);
        unset($this->subscribers, $this->stats);

        session()->flash('ok', 'Added to the list.');
    }

    public function remove(int $id): void
    {
        // Unsubscribed, not deleted: deleting the row would let the same
        // address be added again by an import and start receiving email it
        // already said no to.
        Subscriber::findOrFail($id)->unsubscribe();

        unset($this->subscribers, $this->stats);
        session()->flash('ok', 'Marked as unsubscribed.');
    }

    public function restore(int $id): void
    {
        Subscriber::findOrFail($id)->resubscribe();

        unset($this->subscribers, $this->stats);
    }

    public function saveDigest(): void
    {
        $this->validate([
            'digestSubject' => ['required', 'string', 'max:160'],
            'digestPreheader' => ['nullable', 'string', 'max:160'],
            'digestDay' => ['required', 'integer', 'min:0', 'max:6'],
            'digestTime' => ['required', 'date_format:H:i'],
        ]);

        $this->digest->forceFill([
            'is_active' => $this->digestActive,
            'schedule_day' => $this->digestDay,
            'schedule_time' => $this->digestTime,
            'blocks' => array_values($this->digestBlocks),
            'subject' => $this->digestSubject,
            'preheader' => $this->digestPreheader ?: null,
        ])->save();

        unset($this->digest, $this->stats, $this->preview);

        session()->flash('ok', $this->digestActive
            ? 'Digest is on. It skips any week with nothing worth sending.'
            : 'Digest saved and left off.');
    }

    /** Always to yourself, never to the list. */
    public function sendTest(): void
    {
        $me = Subscriber::firstWhere('user_id', auth()->id());

        if (! $me) {
            $me = Subscriber::add(auth()->user()->email, [
                'user_id' => auth()->id(),
                'name' => auth()->user()->name,
                'source' => 'admin',
            ]);
        }

        \Illuminate\Support\Facades\Mail::to($me->email)
            ->send(new \App\Mail\CampaignMail($this->digest, $me));

        session()->flash('ok', "Test digest sent to {$me->email}.");
    }
}; ?>

<div>
    @if (session('ok'))
        <div class="mb-5 flex items-center gap-3 rounded-xl border border-success/30 bg-success/10 px-4 py-3">
            <x-icon name="circle-check" style="solid" class="text-success" />
            <span class="text-[0.88rem]">{{ session('ok') }}</span>
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
                        'bg-success/15 text-success' => $stat['tone'] === 'success',
                        'bg-info/15 text-info' => $stat['tone'] === 'info',
                        'bg-danger/15 text-danger' => $stat['tone'] === 'danger',
                        'bg-brand/15 text-brand' => $stat['tone'] === 'brand',
                        'bg-raised text-paper/40' => $stat['tone'] === 'neutral',
                    ])>
                        <x-icon :name="$stat['icon']" style="solid" class="text-[13px]" />
                    </span>
                </div>
                <div class="mt-3 truncate text-[1.6rem] font-semibold leading-none tracking-[-0.03em]">{{ $stat['value'] }}</div>
            </div>
        @endforeach
    </div>

    <div class="grid gap-5 xl:grid-cols-[340px_1fr]">

        {{-- ══════ COLUMN 1 ══════ --}}
        <div class="h-fit space-y-5">

            {{-- The digest --}}
            <div class="rounded-2xl border border-hairline bg-panel">
                <div class="flex items-center gap-3 border-b border-hairline px-5 py-4">
                    <x-admin.icon-chip icon="calendar-days" :tone="$digestActive ? 'brand' : 'muted'" />
                    <h2 class="text-[0.95rem] font-medium">Weekly digest</h2>
                </div>

                <div class="space-y-4 p-5">
                    <label class="flex cursor-pointer items-start gap-2.5">
                        <input type="checkbox" wire:model="digestActive"
                               class="mt-0.5 size-4 shrink-0 rounded border-hairline bg-raised text-brand focus:ring-brand" />
                        <span class="text-[0.85rem] leading-relaxed text-paper/70">
                            Send it automatically
                            <span class="block text-[0.75rem] text-paper/30">Skips any week with nothing worth sending.</span>
                        </span>
                    </label>

                    <div class="grid grid-cols-2 gap-3">
                        <label class="block">
                            <span class="mb-2 block text-[0.72rem] uppercase tracking-[0.14em] text-paper/35">Day</span>
                            <select wire:model="digestDay"
                                    class="w-full rounded-lg border-0 bg-raised px-3 py-2.5 text-[0.85rem] text-paper focus:outline-none focus:ring-2 focus:ring-brand/40">
                                @foreach (['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'] as $i => $day)
                                    <option value="{{ $i }}">{{ $day }}</option>
                                @endforeach
                            </select>
                        </label>

                        <label class="block">
                            <span class="mb-2 block text-[0.72rem] uppercase tracking-[0.14em] text-paper/35">Time</span>
                            <input type="time" wire:model="digestTime"
                                   class="w-full rounded-lg border-0 bg-raised px-3 py-2.5 text-[0.85rem] text-paper focus:outline-none focus:ring-2 focus:ring-brand/40" />
                        </label>
                    </div>
                    @error('digestTime') <p class="text-[0.78rem] text-danger">{{ $message }}</p> @enderror

                    <label class="block">
                        <span class="mb-2 block text-[0.72rem] uppercase tracking-[0.14em] text-paper/35">Fallback subject</span>
                        <input type="text" wire:model="digestSubject"
                               class="w-full rounded-lg border-0 bg-raised px-3.5 py-2.5 text-[0.85rem] text-paper focus:outline-none focus:ring-2 focus:ring-brand/40" />
                        @error('digestSubject') <p class="mt-1.5 text-[0.78rem] text-danger">{{ $message }}</p> @enderror
                        <p class="mt-1.5 text-[0.72rem] leading-relaxed text-paper/30">
                            Usually replaced by the real count — “7 new sounds this week”.
                        </p>
                    </label>

                    <label class="block">
                        <span class="mb-2 block text-[0.72rem] uppercase tracking-[0.14em] text-paper/35">Preheader</span>
                        <input type="text" wire:model="digestPreheader"
                               class="w-full rounded-lg border-0 bg-raised px-3.5 py-2.5 text-[0.85rem] text-paper focus:outline-none focus:ring-2 focus:ring-brand/40" />
                        <p class="mt-1.5 text-[0.72rem] leading-relaxed text-paper/30">
                            The grey line next to the subject in the inbox.
                        </p>
                    </label>

                    <div>
                        <span class="mb-2 block text-[0.72rem] uppercase tracking-[0.14em] text-paper/35">What goes in</span>
                        <div class="space-y-2">
                            @foreach (['sounds' => 'New sounds', 'packs' => 'New packs', 'post' => 'Latest blog post'] as $key => $label)
                                <label class="flex cursor-pointer items-center gap-2.5">
                                    <input type="checkbox" wire:model="digestBlocks" value="{{ $key }}"
                                           class="size-4 shrink-0 rounded border-hairline bg-raised text-brand focus:ring-brand" />
                                    <span class="text-[0.85rem] text-paper/70">{{ $label }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <div class="flex gap-2">
                        <button wire:click="saveDigest"
                                class="flex flex-1 items-center justify-center gap-2 rounded-lg bg-action px-4 py-2.5 text-[0.85rem] font-medium text-white transition hover:brightness-110">
                            <x-icon name="check" style="solid" class="text-[11px]" />
                            Save
                        </button>

                        <x-admin.icon-button icon="paper-plane" label="Send a test to yourself"
                                             class="!text-info hover:!bg-info/15" wire:click="sendTest" />
                    </div>
                </div>
            </div>

            {{-- What would go out today --}}
            <div class="rounded-2xl border border-hairline bg-panel p-5">
                <div class="flex items-center gap-3">
                    <x-admin.icon-chip icon="eye" tone="muted" />
                    <div class="min-w-0 flex-1">
                        <div class="text-[0.88rem]">If it went out now</div>
                        <div class="text-[0.75rem] text-paper/30">to {{ number_format($this->reach) }} people</div>
                    </div>
                </div>

                <dl class="mt-4 divide-y divide-hairline text-[0.82rem]">
                    @foreach ([
                        'New sounds' => $this->preview['sounds']->count(),
                        'New packs' => $this->preview['packs']->count(),
                        'Blog post' => $this->preview['post'] ? 1 : 0,
                    ] as $label => $count)
                        <div class="flex items-center justify-between py-2">
                            <dt class="text-paper/35">{{ $label }}</dt>
                            <dd @class(['tabular-nums', 'text-paper/20' => ! $count])>{{ $count }}</dd>
                        </div>
                    @endforeach
                </dl>

                @unless (app(\App\Services\DigestBuilder::class)->worthSending($this->digest))
                    <p class="mt-3 rounded-lg bg-warning/10 px-3.5 py-2.5 text-[0.75rem] leading-relaxed text-paper/60">
                        Too thin to send. “Here is nothing new” teaches people to stop opening, so this week
                        would be skipped.
                    </p>
                @endunless
            </div>

            {{-- Add by hand --}}
            <div class="rounded-2xl border border-hairline bg-panel">
                <div class="flex items-center gap-3 border-b border-hairline px-5 py-4">
                    <x-admin.icon-chip icon="user-plus" tone="brand" />
                    <h2 class="text-[0.95rem] font-medium">Add an address</h2>
                </div>

                <form wire:submit="add" class="space-y-4 p-5">
                    <div>
                        <label class="mb-2 block text-[0.72rem] uppercase tracking-[0.14em] text-paper/35">Email</label>
                        <input type="email" wire:model="email" placeholder="name@example.com"
                               class="w-full rounded-lg border-0 bg-raised px-3.5 py-2.5 text-[0.88rem] text-paper placeholder:text-paper/25 focus:outline-none focus:ring-2 focus:ring-brand/40" />
                        @error('email') <p class="mt-1.5 text-[0.8rem] text-danger">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="mb-2 block text-[0.72rem] uppercase tracking-[0.14em] text-paper/35">Name</label>
                        <input type="text" wire:model="name"
                               class="w-full rounded-lg border-0 bg-raised px-3.5 py-2.5 text-[0.88rem] text-paper focus:outline-none focus:ring-2 focus:ring-brand/40" />
                    </div>

                    <button type="submit"
                            class="flex w-full items-center justify-center gap-2 rounded-lg bg-raised py-2.5 text-[0.85rem] text-paper/70 transition hover:bg-paper/[0.10] hover:text-paper">
                        <x-icon name="plus" style="solid" class="text-[11px]" />
                        Add
                    </button>
                </form>
            </div>
        </div>

        {{-- ══════ COLUMN 2 ══════ --}}
        <div class="min-w-0 rounded-2xl border border-hairline bg-panel">

            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-hairline px-5 py-3.5">
                <h2 class="text-[0.95rem] font-medium">
                    The list <span class="ml-1.5 text-paper/35">{{ $this->subscribers->total() }}</span>
                </h2>

                <div class="flex flex-wrap items-center gap-2">
                    <select wire:model.live="status"
                            class="rounded-lg border-0 bg-raised px-3 py-2 text-[0.82rem] text-paper focus:outline-none focus:ring-2 focus:ring-brand/40">
                        <option value="subscribed">Subscribed</option>
                        <option value="unsubscribed">Unsubscribed</option>
                        <option value="all">Everyone</option>
                    </select>

                    <div class="flex items-center gap-2.5 rounded-lg bg-raised px-3 py-2">
                        <x-icon name="magnifying-glass" style="regular" class="text-[12px] text-paper/35" />
                        <input type="search" wire:model.live.debounce.300ms="search" placeholder="Email or name…"
                               class="w-36 border-0 bg-transparent p-0 text-[0.83rem] text-paper placeholder:text-paper/30 focus:outline-none focus:ring-0" />
                    </div>
                </div>
            </div>

            <table class="w-full text-left">
                <thead>
                    <tr class="border-b border-hairline text-[0.68rem] uppercase tracking-[0.13em] text-paper/30">
                        <th class="w-12 px-5 py-2.5 font-medium">#</th>
                        <th class="px-3 py-2.5 font-medium">Address</th>
                        <th class="w-32 px-3 py-2.5 font-medium">Wants</th>
                        <th class="w-28 px-3 py-2.5 font-medium">Source</th>
                        <th class="w-28 px-3 py-2.5 font-medium">Since</th>
                        <th class="w-[90px] px-5 py-2.5 text-right font-medium">Actions</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-hairline">
                    @php $row = ($this->subscribers->currentPage() - 1) * $this->subscribers->perPage(); @endphp

                    @forelse ($this->subscribers as $item)
                        @php $row++; @endphp

                        <tr wire:key="sub-{{ $item->id }}" @class([
                            'transition hover:bg-paper/[0.03]',
                            'opacity-45' => ! $item->isSubscribed(),
                        ])>
                            <td class="px-5 py-3 text-[0.8rem] tabular-nums text-paper/25">{{ $row }}</td>

                            <td class="px-3 py-3">
                                <div class="flex items-center gap-2">
                                    <span class="truncate text-[0.87rem]">{{ $item->email }}</span>
                                    @if ($item->user?->role === 'collaborator')
                                        <span class="shrink-0 rounded-full bg-raised px-2 py-0.5 text-[0.65rem] text-paper/45">contributor</span>
                                    @endif
                                </div>
                                @if ($item->name)
                                    <div class="truncate text-[0.72rem] text-paper/25">{{ $item->name }}</div>
                                @endif
                            </td>

                            <td class="px-3 py-3">
                                @if (! $item->isSubscribed())
                                    <span class="text-[0.75rem] text-paper/20">—</span>
                                @else
                                    <div class="flex gap-1.5">
                                        @if ($item->wants_digest)
                                            <span class="group/tip relative">
                                                <x-icon name="waveform-lines" style="solid" class="text-[11px] text-brand" />
                                                <span class="pointer-events-none absolute bottom-full left-1/2 z-50 mb-2 -translate-x-1/2 whitespace-nowrap rounded-lg bg-paper px-2.5 py-1 text-[0.7rem] font-medium text-ink opacity-0 shadow-xl transition group-hover/tip:opacity-100">Weekly sounds</span>
                                            </span>
                                        @endif
                                        @if ($item->wants_promos)
                                            <span class="group/tip relative">
                                                <x-icon name="tag" style="solid" class="text-[11px] text-action" />
                                                <span class="pointer-events-none absolute bottom-full left-1/2 z-50 mb-2 -translate-x-1/2 whitespace-nowrap rounded-lg bg-paper px-2.5 py-1 text-[0.7rem] font-medium text-ink opacity-0 shadow-xl transition group-hover/tip:opacity-100">Offers</span>
                                            </span>
                                        @endif
                                    </div>
                                @endif
                            </td>

                            <td class="px-3 py-3 text-[0.75rem] capitalize text-paper/40">{{ $item->source }}</td>

                            <td class="px-3 py-3 text-[0.78rem] text-paper/40">
                                {{ $item->subscribed_at?->format('M j, Y') ?? '—' }}
                            </td>

                            <td class="px-5 py-3">
                                <div class="flex items-center justify-end gap-1">
                                    @if ($item->isSubscribed())
                                        <x-admin.icon-button icon="ban" label="Unsubscribe"
                                                             class="hover:!bg-danger/15 hover:!text-danger"
                                                             wire:click="remove({{ $item->id }})" />
                                    @else
                                        <x-admin.icon-button icon="rotate-left" label="Subscribe again"
                                                             class="hover:!bg-success/15 hover:!text-success"
                                                             wire:click="restore({{ $item->id }})" />
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-5 py-16 text-center">
                                <x-icon name="envelope" style="regular" class="text-[24px] text-paper/20" />
                                <p class="mt-3 text-[0.9rem] text-paper/45">Nobody here yet</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

            @if ($this->subscribers->hasPages())
                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-hairline px-5 py-3.5">
                    <span class="text-[0.78rem] text-paper/35">
                        {{ $this->subscribers->firstItem() }}–{{ $this->subscribers->lastItem() }} of {{ $this->subscribers->total() }}
                    </span>

                    <div class="flex items-center gap-1.5">
                        <x-admin.icon-button icon="chevron-left" variant="muted" label="Previous"
                                             wire:click="previousPage" @disabled($this->subscribers->onFirstPage()) />
                        <x-admin.icon-button icon="chevron-right" variant="muted" label="Next"
                                             wire:click="nextPage" @disabled(! $this->subscribers->hasMorePages()) />
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
