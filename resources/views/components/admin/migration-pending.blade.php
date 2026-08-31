@props([
    'table',
    'what' => 'This screen',
])

{{--
    A pending migration must not take the panel down.

    Every badge count in AdminNav already guards with Schema::hasTable for
    exactly this reason, but the screens did not — so pulling new code and
    opening the page before running migrate produced a 500 instead of the
    one sentence that fixes it. The failure is completely recoverable and
    the recovery is a single command, which makes a crash the worst possible
    way to report it.
--}}
<div class="rounded-2xl border border-warning/25 bg-warning/[0.06] px-5 py-6">
    <div class="flex items-start gap-3.5">
        <span class="grid size-10 shrink-0 place-items-center rounded-full bg-warning/15 text-warning">
            <x-icon name="database" style="solid" class="text-[15px]" />
        </span>

        <div class="min-w-0">
            <h2 class="text-[1.02rem] font-medium">{{ $what }} needs a migration</h2>

            <p class="mt-1.5 max-w-[64ch] text-[0.85rem] leading-relaxed text-paper/55">
                The table <code class="rounded bg-raised px-1.5 py-0.5 text-[0.76rem] text-paper/75">{{ $table }}</code>
                does not exist yet. The code that reads it is already here; the database has not caught up.
            </p>

            <code class="mt-3.5 inline-block rounded-lg bg-rail px-3.5 py-2 font-mono text-[0.8rem] text-paper/80">php artisan migrate</code>

            <div class="mt-4">
                <a href="{{ route('admin.diagnostics') }}" wire:navigate
                   class="inline-flex items-center gap-2 rounded-lg bg-raised px-3 py-1.5 text-[0.78rem] transition hover:bg-brand hover:text-white">
                    See every pending migration
                    <x-icon name="arrow-right" style="solid" class="text-[0.68rem]" />
                </a>
            </div>
        </div>
    </div>
</div>
