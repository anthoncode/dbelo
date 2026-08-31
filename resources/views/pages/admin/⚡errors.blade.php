<?php

use App\Models\ErrorGroup;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.admin')] #[Title('Error logs')] class extends Component {
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: 'open')]
    public string $status = 'open';

    public ?int $expanded = null;

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    public function updated(string $key): void
    {
        if (in_array($key, ['search', 'status'], true)) {
            $this->resetPage();
            $this->expanded = null;
        }
    }

    #[Computed]
    public function groups()
    {
        return ErrorGroup::query()
            ->search($this->search)
            ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))
            ->orderByDesc('last_seen_at')
            ->paginate(20);
    }

    /**
     * The three numbers worth putting above the list.
     *
     * Not "total errors ever", which only goes up and therefore says
     * nothing. Open groups is the size of the backlog; the last 24 hours is
     * whether it is happening NOW; new today is the alarm.
     */
    #[Computed]
    public function summary(): array
    {
        return [
            'open' => ErrorGroup::where('status', 'open')->count(),
            'recent' => ErrorGroup::where('last_seen_at', '>=', now()->subDay())->count(),
            'new' => ErrorGroup::where('first_seen_at', '>=', now()->subDay())->count(),
            'regressed' => ErrorGroup::where('status', 'open')->whereNotNull('regressed_at')->count(),
        ];
    }

    #[Computed]
    public function statusCounts(): array
    {
        return [
            'open' => ErrorGroup::where('status', 'open')->count(),
            'resolved' => ErrorGroup::where('status', 'resolved')->count(),
            'muted' => ErrorGroup::where('status', 'muted')->count(),
            'all' => ErrorGroup::count(),
        ];
    }

    public function toggle(int $id): void
    {
        $this->expanded = $this->expanded === $id ? null : $id;
    }

    public function resolve(int $id): void
    {
        ErrorGroup::findOrFail($id)->resolve();

        unset($this->groups, $this->summary, $this->statusCounts);
    }

    public function mute(int $id): void
    {
        ErrorGroup::findOrFail($id)->mute();

        unset($this->groups, $this->summary, $this->statusCounts);
    }

    public function reopen(int $id): void
    {
        ErrorGroup::findOrFail($id)->reopen();

        unset($this->groups, $this->summary, $this->statusCounts);
    }
}; ?>

<div class="space-y-5">

    {{-- ══════════════════════════════════════════════════════════════
         THE THREE NUMBERS
         ══════════════════════════════════════════════════════════════ --}}
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ([
            ['label' => 'Open', 'value' => $this->summary['open'], 'icon' => 'folder-open', 'tone' => 'muted', 'hint' => 'distinct problems'],
            ['label' => 'Seen in 24h', 'value' => $this->summary['recent'], 'icon' => 'clock', 'tone' => 'muted', 'hint' => 'still happening'],
            ['label' => 'New today', 'value' => $this->summary['new'], 'icon' => 'bolt', 'tone' => 'warning', 'hint' => 'first time ever'],
            ['label' => 'Came back', 'value' => $this->summary['regressed'], 'icon' => 'rotate-left', 'tone' => 'danger', 'hint' => 'after being resolved'],
        ] as $card)
            <div class="rounded-2xl border border-hairline bg-panel p-5" wire:key="sum-{{ $card['label'] }}">
                <div class="flex items-start justify-between">
                    <span class="text-[0.72rem] uppercase tracking-[0.14em] text-paper/35">{{ $card['label'] }}</span>
                    <span @class([
                        'grid size-9 place-items-center rounded-full',
                        'bg-danger/15 text-danger' => $card['tone'] === 'danger' && $card['value'] > 0,
                        'bg-warning/15 text-warning' => $card['tone'] === 'warning' && $card['value'] > 0,
                        'bg-raised text-paper/45' => $card['value'] === 0 || $card['tone'] === 'muted',
                    ])>
                        <x-icon :name="$card['icon']" style="solid" class="text-[13px]" />
                    </span>
                </div>

                <div class="mt-3 text-[2rem] font-semibold leading-none tracking-[-0.03em]">
                    {{ number_format($card['value']) }}
                </div>

                <div class="mt-2 text-[0.78rem] text-paper/35">{{ $card['hint'] }}</div>
            </div>
        @endforeach
    </div>

    {{-- ══════════════════════════════════════════════════════════════
         THE LIST
         ══════════════════════════════════════════════════════════════ --}}
    <div class="rounded-2xl border border-hairline bg-panel">
        <div class="flex flex-wrap items-center gap-3 border-b border-hairline px-5 py-4">
            <div class="relative min-w-[220px] flex-1">
                {{-- The icon sits in a fixed-width well, not at a left
                     offset. Positioned by `left-3.5` its right edge depends
                     on how wide the glyph happens to render — which changes
                     with the icon, the font size, and whether Font Awesome
                     has loaded yet — so the text could start before the icon
                     ended. A well the same width as the padding cannot
                     collide: the two are the same number by construction. --}}
                <span class="pointer-events-none absolute inset-y-0 left-0 grid w-11 place-items-center">
                    <x-icon name="magnifying-glass" style="solid" class="text-[0.78rem] text-paper/30" />
                </span>
                <input type="search" wire:model.live.debounce.300ms="search"
                       placeholder="Message, exception class, file…"
                       class="w-full rounded-lg border-0 bg-raised py-2.5 pl-11 pr-3.5 text-[0.84rem] placeholder:text-paper/30 focus:outline-none focus:ring-1 focus:ring-brand" />
            </div>

            @foreach (['open' => 'Open', 'resolved' => 'Resolved', 'muted' => 'Muted', 'all' => 'All'] as $key => $label)
                <button type="button" wire:click="$set('status', '{{ $key }}')" wire:key="st-{{ $key }}"
                        @class([
                            'flex items-center gap-2 rounded-full px-3.5 py-1.5 text-[0.78rem] transition duration-200 ease-dbelo',
                            'bg-brand text-white' => $status === $key,
                            'bg-raised text-paper/55 hover:text-paper' => $status !== $key,
                        ])>
                    {{ $label }}
                    <span class="tabular-nums opacity-50">{{ $this->statusCounts[$key] }}</span>
                </button>
            @endforeach
        </div>

        <div class="divide-y divide-hairline">
            @forelse ($this->groups as $group)
                @php $trend = $group->trend(30); $peak = max(1, max(array_column($trend, 'count'))); @endphp

                <div wire:key="err-{{ $group->id }}">
                    <div class="flex items-start gap-3.5 px-5 py-4">

                        <span @class([
                            'mt-1.5 size-2 shrink-0 rounded-full',
                            'bg-danger' => $group->level === 'critical',
                            'bg-warning' => $group->level !== 'critical',
                        ])></span>

                        <button type="button" wire:click="toggle({{ $group->id }})" class="min-w-0 flex-1 text-left">
                            <span class="flex flex-wrap items-center gap-2">
                                <span class="font-mono text-[0.82rem] text-paper/80">{{ $group->shortClass() }}</span>

                                @if ($group->hasRegressed())
                                    {{-- The most important badge on the screen: the
                                         fix did not fix it. --}}
                                    <span class="rounded-full bg-danger/15 px-2 py-0.5 text-[0.66rem] font-semibold uppercase tracking-[0.1em] text-danger">
                                        Came back
                                    </span>
                                @elseif ($group->isNew())
                                    <span class="rounded-full bg-warning/15 px-2 py-0.5 text-[0.66rem] font-semibold uppercase tracking-[0.1em] text-warning">
                                        New
                                    </span>
                                @endif

                                @if ($group->status === 'muted')
                                    <span class="rounded-full bg-raised px-2 py-0.5 text-[0.66rem] uppercase tracking-[0.1em] text-paper/40">
                                        Muted
                                    </span>
                                @elseif ($group->status === 'resolved')
                                    <span class="rounded-full bg-success/15 px-2 py-0.5 text-[0.66rem] uppercase tracking-[0.1em] text-success">
                                        Resolved
                                    </span>
                                @endif
                            </span>

                            <span class="mt-1 block text-[0.88rem] leading-relaxed text-paper/70">
                                {{ $group->shortMessage() }}
                            </span>

                            <span class="mt-1.5 flex flex-wrap items-center gap-x-2.5 gap-y-1 text-[0.75rem] text-paper/35">
                                @if ($group->location())
                                    <span class="font-mono">{{ $group->location() }}</span>
                                    <span class="opacity-40">·</span>
                                @endif
                                <span title="{{ $group->last_seen_at?->toDayDateTimeString() }}">
                                    last {{ $group->last_seen_at?->diffForHumans() }}
                                </span>
                                <span class="opacity-40">·</span>
                                <span title="{{ $group->first_seen_at?->toDayDateTimeString() }}">
                                    first {{ $group->first_seen_at?->diffForHumans() }}
                                </span>
                            </span>
                        </button>

                        {{-- 30-day trend. Thirty bars answer "is this getting
                             worse?" in the space a number cannot. --}}
                        <div class="hidden shrink-0 items-end gap-[2px] lg:flex" title="Last 30 days">
                            @foreach ($trend as $day)
                                <span @class([
                                        'w-[3px] rounded-full',
                                        'bg-brand' => $day['count'] > 0,
                                        'bg-paper/10' => $day['count'] === 0,
                                    ])
                                      style="height: {{ $day['count'] > 0 ? max(3, (int) round($day['count'] / $peak * 24)) : 3 }}px"></span>
                            @endforeach
                        </div>

                        <div class="shrink-0 text-right">
                            <div class="text-[0.95rem] font-semibold tabular-nums">{{ number_format($group->count) }}</div>
                            <div class="text-[0.7rem] text-paper/30">events</div>
                        </div>

                        <div class="flex shrink-0 items-center gap-1.5">
                            @if ($group->status === 'open')
                                <x-admin.icon-button icon="check" variant="muted" label="Resolve"
                                                     wire:click="resolve({{ $group->id }})" />
                                <x-admin.icon-button icon="bell-slash" variant="muted" label="Mute"
                                                     wire:click="mute({{ $group->id }})" />
                            @else
                                <x-admin.icon-button icon="rotate-left" variant="muted" label="Reopen"
                                                     wire:click="reopen({{ $group->id }})" />
                            @endif
                        </div>
                    </div>

                    @if ($this->expanded === $group->id)
                        <div class="border-t border-hairline bg-rail/40 px-5 py-4">

                            @if ($group->last_context)
                                <div class="mb-4 flex flex-wrap gap-x-6 gap-y-2 text-[0.8rem]">
                                    @foreach ($group->last_context as $key => $value)
                                        <span wire:key="ctx-{{ $group->id }}-{{ $key }}">
                                            <span class="text-paper/30">{{ $key }}</span>
                                            <span class="ml-1.5 break-all text-paper/70">{{ $value }}</span>
                                        </span>
                                    @endforeach
                                </div>
                            @endif

                            <div class="mb-3 text-[0.84rem] leading-relaxed text-paper/70">{{ $group->message }}</div>

                            {{-- What it means and what to do.

                                 A stack trace says where the program stopped;
                                 it does not say why. For most of the errors a
                                 Laravel application actually produces, the
                                 "why" is one of about twenty stories that
                                 repeat forever — and writing those down turns
                                 this from a place where you learn something
                                 broke into a place where you learn what to do.

                                 No hint is a fine answer. An invented one is
                                 worse than none, because somebody follows it. --}}
                            @php $hint = App\Support\ErrorHints::for($group); @endphp

                            @if ($hint)
                                <div class="mb-4 rounded-xl border border-info/20 bg-info/[0.06] px-4 py-3.5">
                                    <div class="flex items-start gap-3">
                                        <x-icon name="lightbulb" style="solid" class="mt-0.5 shrink-0 text-[0.85rem] text-info" />

                                        <div class="min-w-0 flex-1">
                                            <div class="text-[0.88rem] font-medium text-paper/85">{{ $hint['title'] }}</div>

                                            <p class="mt-1.5 text-[0.82rem] leading-relaxed text-paper/60">{{ $hint['what'] }}</p>

                                            <div class="mt-3 text-[0.72rem] uppercase tracking-[0.12em] text-paper/35">How to fix it</div>

                                            <ol class="mt-1.5 space-y-1.5">
                                                @foreach ($hint['fix'] as $i => $step)
                                                    <li class="flex items-start gap-2.5 text-[0.82rem] leading-relaxed text-paper/70"
                                                        wire:key="fix-{{ $group->id }}-{{ $i }}">
                                                        <span class="mt-[0.15rem] grid size-4 shrink-0 place-items-center rounded-full bg-info/15 text-[0.62rem] font-semibold text-info">
                                                            {{ $i + 1 }}
                                                        </span>

                                                        {{-- A step that is a command is shown as one, so it
                                                             can be copied instead of retyped. --}}
                                                        @if (Str::startsWith($step, ['php artisan', 'composer', 'npm', 'which ']))
                                                            <code class="rounded bg-rail px-2 py-0.5 font-mono text-[0.76rem] text-paper/80">{{ $step }}</code>
                                                        @else
                                                            <span>{{ $step }}</span>
                                                        @endif
                                                    </li>
                                                @endforeach
                                            </ol>

                                            @isset($hint['route'])
                                                <a href="{{ route($hint['route']) }}" wire:navigate
                                                   class="mt-3.5 inline-flex items-center gap-2 rounded-lg bg-raised px-3 py-1.5 text-[0.78rem] transition hover:bg-brand hover:text-white">
                                                    {{ $hint['routeLabel'] ?? 'Open' }}
                                                    <x-icon name="arrow-right" style="solid" class="text-[0.68rem]" />
                                                </a>
                                            @endisset
                                        </div>
                                    </div>
                                </div>
                            @endif

                            @if ($group->last_trace)
                                <pre class="max-h-96 overflow-auto rounded-lg bg-rail px-4 py-3 font-mono text-[0.72rem] leading-relaxed text-paper/55">{{ $group->last_trace }}</pre>
                            @endif

                            @if ($group->status === 'resolved' && $group->resolver)
                                <p class="mt-3 text-[0.78rem] text-paper/35">
                                    Resolved by {{ $group->resolver->name }}, {{ $group->resolved_at?->diffForHumans() }}.
                                </p>
                            @endif
                        </div>
                    @endif
                </div>
            @empty
                <div class="px-5 py-16 text-center">
                    <x-icon name="circle-check" style="solid" class="text-[24px] text-success" />
                    <p class="mt-3 text-[0.9rem] text-paper/50">
                        {{ $status === 'open' ? 'Nothing open' : 'Nothing here' }}
                    </p>
                    <p class="mt-1 text-[0.8rem] text-paper/30">
                        404s, failed validation and expired sessions are not errors and never reach this screen.
                    </p>
                </div>
            @endforelse
        </div>

        <div class="border-t border-hairline">
            <x-admin.pagination :paginator="$this->groups" prefix="err" />
        </div>
    </div>

    {{-- ══════════════════════════════════════════════════════════════
         WHAT THIS SCREEN IS AND IS NOT
         ══════════════════════════════════════════════════════════════ --}}
    <div class="rounded-2xl border border-hairline bg-panel px-5 py-4">
        <p class="text-[0.78rem] leading-relaxed text-paper/40">
            <x-icon name="circle-info" style="solid" class="mr-1 text-[0.72rem] text-info" />
            One row per <em>distinct</em> problem, matched on exception class, the message with
            ids and numbers stripped out, and the first line of your own code in the trace.
            <strong class="text-paper/60">Resolving does not hide anything</strong> — it means
            "tell me if this happens again", and when it does the group reopens with a
            <span class="text-danger">Came back</span> badge, which is the tracker telling you the fix did not work.
            <span class="mt-1.5 block">
                <code class="rounded bg-raised px-1.5 py-0.5 text-[0.72rem] text-paper/60">storage/logs/laravel.log</code>
                still receives everything as well. A table cannot record the error that happens while the database is down.
            </span>
        </p>
    </div>
</div>
