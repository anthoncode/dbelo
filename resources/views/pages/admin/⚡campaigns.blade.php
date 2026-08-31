<?php

use App\Models\Campaign;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.admin')] #[Title('Campaigns')] class extends Component {
    use WithPagination;

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    #[Computed]
    public function campaigns()
    {
        return Campaign::promos()
            ->with('author:id,name')
            ->latest('id')
            ->paginate(10);
    }

    public function duplicate(int $id): void
    {
        $original = Campaign::findOrFail($id);

        $copy = $original->replicate(['status', 'sent_at', 'started_at', 'scheduled_at',
            'recipients_count', 'sent_count', 'failed_count']);

        $copy->title = $original->title.' (copy)';
        $copy->status = Campaign::STATUS_DRAFT;
        $copy->user_id = auth()->id();
        $copy->save();

        $this->redirectRoute('admin.campaigns.edit', $copy, navigate: true);
    }

    public function trash(int $id): void
    {
        $campaign = Campaign::findOrFail($id);

        if ($campaign->isSent()) {
            session()->flash('error', 'A campaign that has been sent stays on the record.');

            return;
        }

        $campaign->delete();
        unset($this->campaigns);

        session()->flash('ok', 'Draft deleted.');
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

    <div class="rounded-2xl border border-hairline bg-panel">

        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-hairline px-5 py-3.5">
            <div>
                <h2 class="text-[0.95rem] font-medium">
                    Promotions <span class="ml-1.5 text-paper/35">{{ $this->campaigns->total() }}</span>
                </h2>
                <p class="mt-0.5 text-[0.75rem] text-paper/30">
                    One-offs you write. The weekly digest lives in
                    <a href="{{ route('admin.subscribers') }}" wire:navigate class="underline hover:text-paper">Subscribers</a>.
                </p>
            </div>

            <a href="{{ route('admin.campaigns.create') }}" wire:navigate
               class="flex items-center gap-2 rounded-lg bg-action px-4 py-2.5 text-[0.83rem] font-medium text-white transition hover:brightness-110">
                <x-icon name="plus" style="solid" class="text-[11px]" />
                New promotion
            </a>
        </div>

        <table class="w-full text-left">
            <thead>
                <tr class="border-b border-hairline text-[0.68rem] uppercase tracking-[0.13em] text-paper/30">
                    <th class="w-12 px-5 py-2.5 font-medium">#</th>
                    <th class="px-3 py-2.5 font-medium">Campaign</th>
                    <th class="w-36 px-3 py-2.5 font-medium">Segment</th>
                    <th class="w-28 px-3 py-2.5 font-medium">Status</th>
                    <th class="w-32 px-3 py-2.5 font-medium">Delivered</th>
                    <th class="w-28 px-3 py-2.5 font-medium">Sent</th>
                    <th class="w-[110px] px-5 py-2.5 text-right font-medium">Actions</th>
                </tr>
            </thead>

            <tbody class="divide-y divide-hairline">
                @php $row = ($this->campaigns->currentPage() - 1) * $this->campaigns->perPage(); @endphp

                @forelse ($this->campaigns as $campaign)
                    @php $row++; @endphp

                    <tr wire:key="camp-{{ $campaign->id }}" class="transition hover:bg-paper/[0.03]">
                        <td class="px-5 py-3 text-[0.8rem] tabular-nums text-paper/25">{{ $row }}</td>

                        <td class="px-3 py-3">
                            <a href="{{ route('admin.campaigns.edit', $campaign) }}" wire:navigate
                               class="block truncate text-[0.89rem] transition hover:text-brand">{{ $campaign->title }}</a>
                            <div class="truncate text-[0.72rem] text-paper/25">{{ $campaign->subject }}</div>
                        </td>

                        <td class="px-3 py-3">
                            <span class="rounded-full bg-raised px-2.5 py-1 text-[0.72rem] text-paper/60">{{ $campaign->segmentLabel() }}</span>
                        </td>

                        <td class="px-3 py-3">
                            <span @class([
                                'rounded-full px-2.5 py-1 text-[0.72rem] capitalize',
                                'bg-raised text-paper/45' => $campaign->status === 'draft',
                                'bg-info/15 text-info' => $campaign->status === 'scheduled',
                                'bg-warning/15 text-warning' => $campaign->status === 'sending',
                                'bg-success/15 text-success' => $campaign->status === 'sent',
                            ])>{{ $campaign->status }}</span>
                        </td>

                        <td class="px-3 py-3">
                            @if ($campaign->recipients_count)
                                <div class="flex items-center gap-2">
                                    <div class="h-1.5 w-14 overflow-hidden rounded-full bg-raised">
                                        <div class="h-full rounded-full bg-success"
                                             style="width: {{ min(100, round($campaign->sent_count / max(1, $campaign->recipients_count) * 100)) }}%"></div>
                                    </div>
                                    <span class="text-[0.76rem] tabular-nums text-paper/55">
                                        {{ number_format($campaign->sent_count) }}/{{ number_format($campaign->recipients_count) }}
                                    </span>
                                </div>
                                @if ($campaign->failed_count)
                                    <div class="mt-1 text-[0.7rem] text-danger">{{ $campaign->failed_count }} failed</div>
                                @endif
                            @else
                                <span class="text-[0.75rem] text-paper/20">—</span>
                            @endif
                        </td>

                        <td class="px-3 py-3 text-[0.78rem] text-paper/40">
                            {{ $campaign->sent_at?->format('M j, Y') ?? '—' }}
                        </td>

                        <td class="px-5 py-3">
                            <div class="flex items-center justify-end gap-1">
                                <a href="{{ route('admin.campaigns.edit', $campaign) }}" wire:navigate>
                                    <x-admin.icon-button :icon="$campaign->isEditable() ? 'pen-to-square' : 'eye'"
                                                         :label="$campaign->isEditable() ? 'Edit' : 'View'" />
                                </a>

                                <x-admin.icon-button icon="copy" label="Duplicate" wire:click="duplicate({{ $campaign->id }})" />

                                @unless ($campaign->isSent())
                                    <x-admin.icon-button icon="trash" label="Delete"
                                                         class="hover:!bg-danger/15 hover:!text-danger"
                                                         wire:click="trash({{ $campaign->id }})"
                                                         wire:confirm="Delete “{{ $campaign->title }}”?" />
                                @endunless
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-5 py-16 text-center">
                            <x-icon name="paper-plane" style="regular" class="text-[24px] text-paper/20" />
                            <p class="mt-3 text-[0.9rem] text-paper/45">No promotions yet</p>
                            <p class="mt-1 text-[0.8rem] text-paper/25">
                                The weekly digest goes out on its own — this is for the occasional announcement.
                            </p>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        @if ($this->campaigns->hasPages())
            <div class="flex items-center justify-between gap-3 border-t border-hairline px-5 py-3.5">
                <span class="text-[0.78rem] text-paper/35">
                    {{ $this->campaigns->firstItem() }}–{{ $this->campaigns->lastItem() }} of {{ $this->campaigns->total() }}
                </span>
                <div class="flex items-center gap-1.5">
                    <x-admin.icon-button icon="chevron-left" variant="muted" label="Previous"
                                         wire:click="previousPage" @disabled($this->campaigns->onFirstPage()) />
                    <x-admin.icon-button icon="chevron-right" variant="muted" label="Next"
                                         wire:click="nextPage" @disabled(! $this->campaigns->hasMorePages()) />
                </div>
            </div>
        @endif
    </div>
</div>
