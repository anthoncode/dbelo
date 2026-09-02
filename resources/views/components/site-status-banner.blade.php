{{--
    The reminder that the site is closed and you are the only one who cannot
    tell.

    The bypass is what makes the switch safe, and it is also what makes it
    dangerous: admins see the real site, so "coming soon" left on after
    launch looks completely normal from the inside. Nothing about the site
    would tell you. This does.

    Same shape as the impersonation banner directly above it, on purpose —
    both say the same kind of thing: what you are looking at is not what
    everybody else is looking at.
--}}
@php
    $status = \App\Support\SiteStatus::current();
@endphp

@if ($status !== \App\Support\SiteStatus::LIVE && auth()->user()?->isAdmin())
    <div @class([
        'flex flex-wrap items-center justify-center gap-3 px-4 py-2.5 text-center text-white',
        'bg-info' => $status === \App\Support\SiteStatus::SOON,
        'bg-warning' => $status === \App\Support\SiteStatus::MAINTENANCE,
    ])>
        <x-icon :name="$status === 'soon' ? 'hourglass-half' : 'screwdriver-wrench'" style="solid" class="text-sm" />

        <span class="text-[0.86rem]">
            @if ($status === \App\Support\SiteStatus::SOON)
                The site is <strong>not published</strong>. Visitors see a coming-soon page; you see the real thing.
            @else
                The site is in <strong>maintenance</strong>. Visitors are being turned away.
            @endif
        </span>

        <a href="{{ route('admin.settings.general') }}" wire:navigate
           class="rounded-full bg-white/20 px-4 py-1 text-[0.8rem] font-medium transition hover:bg-white/30">
            Open it
        </a>
    </div>
@endif
