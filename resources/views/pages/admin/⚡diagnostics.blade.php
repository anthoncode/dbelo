<?php

use App\Services\Diagnostics;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.admin')] #[Title('Diagnostics')] class extends Component {
    public ?string $ranAt = null;

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    /**
     * Checks are run live on every render, never cached.
     *
     * A cached diagnostic is worse than none: you look at it precisely
     * because something feels wrong NOW, and a five-minute-old answer sends
     * you to fix a thing that is already fixed — or reassures you about a
     * thing that just broke.
     */
    #[Computed]
    public function checks(): array
    {
        return app(Diagnostics::class)->run();
    }

    #[Computed]
    public function grouped(): array
    {
        $grouped = [];

        foreach ($this->checks as $check) {
            $grouped[$check['group']][] = $check;
        }

        return $grouped;
    }

    #[Computed]
    public function summary(): array
    {
        return app(Diagnostics::class)->summary($this->checks);
    }

    /** Problems first — the list is read top to bottom and stops early. */
    #[Computed]
    public function problems(): array
    {
        return array_values(array_filter(
            $this->checks,
            fn ($c) => $c['status'] !== Diagnostics::OK,
        ));
    }

    public function refresh(): void
    {
        // The source-mtime scan is cached for a minute so it does not walk
        // resources/ on every keystroke elsewhere. An explicit refresh is a
        // person asking for the truth, so it clears that first.
        cache()->forget('diagnostics.source.mtime');

        unset($this->checks, $this->grouped, $this->summary, $this->problems);

        $this->ranAt = now()->format('H:i:s');
    }
}; ?>

<div class="space-y-5">

    {{-- ══════════════════════════════════════════════════════════════
         VERDICT
         ══════════════════════════════════════════════════════════════ --}}
    @php
        $summary = $this->summary;
        $verdict = $summary['fail'] > 0 ? 'fail' : ($summary['warn'] > 0 ? 'warn' : 'ok');
    @endphp

    <div @class([
        'rounded-2xl border px-5 py-5',
        'border-danger/25 bg-danger/[0.06]' => $verdict === 'fail',
        'border-warning/25 bg-warning/[0.06]' => $verdict === 'warn',
        'border-success/25 bg-success/[0.06]' => $verdict === 'ok',
    ])>
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="flex items-start gap-3.5">
                <span @class([
                    'grid size-10 shrink-0 place-items-center rounded-full',
                    'bg-danger/15 text-danger' => $verdict === 'fail',
                    'bg-warning/15 text-warning' => $verdict === 'warn',
                    'bg-success/15 text-success' => $verdict === 'ok',
                ])>
                    <x-icon :name="$verdict === 'ok' ? 'circle-check' : ($verdict === 'fail' ? 'circle-exclamation' : 'triangle-exclamation')"
                            style="solid" class="text-[15px]" />
                </span>

                <div>
                    <h2 class="text-[1.05rem] font-medium">
                        @if ($verdict === 'ok')
                            Everything is wired up
                        @elseif ($verdict === 'fail')
                            {{ $summary['fail'] }} {{ Str::plural('thing', $summary['fail']) }} {{ $summary['fail'] === 1 ? 'is' : 'are' }} broken
                        @else
                            {{ $summary['warn'] }} {{ Str::plural('thing', $summary['warn']) }} worth a look
                        @endif
                    </h2>

                    <p class="mt-1 max-w-[62ch] text-[0.84rem] leading-relaxed text-paper/50">
                        This screen is for what goes wrong <em>without</em> anything being thrown — a mismatch between
                        the code and the machine it runs on. Those produce wrong behaviour rather than errors, so
                        nothing appears in any log and the only way to find them is to come and look.
                    </p>
                </div>
            </div>

            <div class="flex shrink-0 items-center gap-3">
                @if ($this->ranAt)
                    <span class="text-[0.75rem] tabular-nums text-paper/30">checked {{ $this->ranAt }}</span>
                @endif

                <button type="button" wire:click="refresh" wire:loading.attr="disabled"
                        class="flex items-center gap-2 rounded-lg bg-raised px-3.5 py-2 text-[0.82rem] transition hover:bg-brand hover:text-white disabled:opacity-50">
                    <x-icon name="rotate" style="solid" class="text-[0.75rem]" wire:loading.class="fa-spin" wire:target="refresh" />
                    Run again
                </button>
            </div>
        </div>
    </div>

    {{-- ══════════════════════════════════════════════════════════════
         WHAT TO DO

         Problems lifted out of their groups and put first, with the fix.
         A report you have to scan for the red is a report; a list of the
         things that need doing is a tool.
         ══════════════════════════════════════════════════════════════ --}}
    @if ($this->problems)
        <div class="rounded-2xl border border-hairline bg-panel">
            <div class="border-b border-hairline px-5 py-4">
                <h2 class="text-[0.95rem] font-medium">What to do</h2>
            </div>

            <div class="divide-y divide-hairline">
                @foreach ($this->problems as $check)
                    <div class="flex items-start gap-3.5 px-5 py-4" wire:key="fix-{{ $check['key'] }}">
                        <span @class([
                            'mt-1.5 size-2 shrink-0 rounded-full',
                            'bg-danger' => $check['status'] === 'fail',
                            'bg-warning' => $check['status'] === 'warn',
                        ])></span>

                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-baseline gap-2">
                                <span class="text-[0.9rem] text-paper/85">{{ $check['label'] }}</span>
                                <span class="text-[0.75rem] uppercase tracking-[0.12em] text-paper/30">{{ $check['group'] }}</span>
                            </div>

                            <p class="mt-1 text-[0.84rem] text-paper/60">{{ $check['detail'] }}</p>

                            @if ($check['fix'])
                                <p class="mt-2 max-w-[70ch] text-[0.82rem] leading-relaxed text-paper/45">{{ $check['fix'] }}</p>
                            @endif

                            @if ($check['command'])
                                <code class="mt-2.5 inline-block rounded-lg bg-rail px-3 py-1.5 font-mono text-[0.78rem] text-paper/80">{{ $check['command'] }}</code>
                            @endif
                        </div>

                        @if ($check['route'])
                            <a href="{{ route($check['route']) }}" wire:navigate
                               class="shrink-0 rounded-lg bg-raised px-3 py-1.5 text-[0.78rem] transition hover:bg-brand hover:text-white">
                                Open
                            </a>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- ══════════════════════════════════════════════════════════════
         EVERY CHECK
         ══════════════════════════════════════════════════════════════ --}}
    @foreach ($this->grouped as $group => $checks)
        <div class="rounded-2xl border border-hairline bg-panel" wire:key="grp-{{ $group }}">
            <div class="border-b border-hairline px-5 py-3.5">
                <span class="text-[0.72rem] uppercase tracking-[0.14em] text-paper/35">{{ $group }}</span>
            </div>

            <div class="divide-y divide-hairline">
                @foreach ($checks as $check)
                    <div class="flex items-center gap-3.5 px-5 py-3" wire:key="chk-{{ $check['key'] }}">
                        <span @class([
                            'size-2 shrink-0 rounded-full',
                            'bg-success' => $check['status'] === 'ok',
                            'bg-warning' => $check['status'] === 'warn',
                            'bg-danger' => $check['status'] === 'fail',
                        ])></span>

                        <span class="w-40 shrink-0 truncate text-[0.86rem] text-paper/75">{{ $check['label'] }}</span>

                        <span class="min-w-0 flex-1 text-[0.82rem] text-paper/45">{{ $check['detail'] }}</span>

                        @if ($check['route'])
                            <a href="{{ route($check['route']) }}" wire:navigate
                               class="shrink-0 text-[0.78rem] text-paper/35 transition hover:text-brand">
                                <x-icon name="arrow-up-right-from-square" style="solid" class="text-[0.7rem]" />
                            </a>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endforeach

    <div class="rounded-2xl border border-hairline bg-panel px-5 py-4">
        <p class="text-[0.78rem] leading-relaxed text-paper/40">
            <x-icon name="terminal" style="solid" class="mr-1 text-[0.72rem] text-info" />
            The same checks run in a terminal with
            <code class="rounded bg-raised px-1.5 py-0.5 text-[0.72rem] text-paper/60">php artisan dbelo:doctor</code>,
            which exits non-zero when something is failing — so it works in a deploy script.
            One definition, two surfaces: written twice they would disagree the first time either was edited,
            and then neither could be trusted.
        </p>
    </div>
</div>
