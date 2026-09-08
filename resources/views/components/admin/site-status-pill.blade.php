@php
    $status = \App\Support\SiteStatus::current();
    $live = \App\Support\SiteStatus::LIVE;
@endphp

{{--
    "The site is closed and you are the only one who cannot tell."

    THE SAME FACT THE BANNER SAYS, on the surface the banner cannot reach.
    x-site-status-banner already warns an admin browsing the public site,
    which covers the case where you go and look. It does not cover the case
    where you spend an afternoon in the panel — editing, publishing,
    uploading — for a site that is turning every visitor away. Nothing in
    here would have told you.

    Both read App\Support\SiteStatus::current(), so this is one definition on
    two surfaces rather than two things that can disagree.

    INVISIBLE WHEN LIVE, which is the whole design. A permanent "status: OK"
    chip is read once and never again, and then it is still there on the day
    it says something else. This appears only when the answer changed, so its
    presence is the message.

    The tones are written out as whole class names on purpose. SiteStatus
    hands back a tone NAME, and a Tailwind class assembled from a variable
    was never seen by the compiler and does not exist in the stylesheet.
--}}
@if ($status !== $live)
    <a href="{{ route('admin.settings.general') }}" wire:navigate
       title="Visitors cannot reach the site. Click to change it in Settings → General."
       @class([
           'flex shrink-0 items-center gap-2 rounded-full px-3 py-1 text-[0.74rem] font-medium transition',
           'bg-info/15 text-info hover:bg-info/25' => $status === \App\Support\SiteStatus::SOON,
           'bg-warning/15 text-warning hover:bg-warning/25' => $status === \App\Support\SiteStatus::MAINTENANCE,
       ])>
        <x-icon :name="$status === \App\Support\SiteStatus::SOON ? 'hourglass-half' : 'screwdriver-wrench'"
                style="solid" class="text-[0.7rem]" />

        {{-- Says what is true of the SITE, not what the setting is called.
             "Coming soon" is the name of a switch; "Not published" is the
             thing you need to know at a glance. --}}
        {{ $status === \App\Support\SiteStatus::SOON ? 'Not published' : 'Maintenance' }}
    </a>
@endif
