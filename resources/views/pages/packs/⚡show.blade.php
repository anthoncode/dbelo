<?php

use App\Models\Collection as Pack;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.site')] #[Title('Sound effect pack')] class extends Component {
    public Pack $pack;

    /** True when the pack exists but is not public. Admins only. */
    public bool $isHidden = false;

    /**
     * Looked up by hand rather than through route model binding.
     *
     * The binding would resolve any collection, and most collections are
     * somebody's private folder. Scoping the lookup here means a private
     * one is a 404 at this URL instead of a leak — the check cannot be
     * forgotten because it IS the lookup.
     *
     * ── WHY THE TWO CONDITIONS ARE NOW CHECKED SEPARATELY ────────────────
     *
     * This was one line: featured()->where(slug)->firstOrFail(). Correct,
     * and useless to debug. featured() means is_featured AND is_public, so
     * a pack you built, filled and forgot to publish returned exactly the
     * same 404 as a URL that never existed — and the person most likely to
     * hit it is the one who made the pack.
     *
     * Split apart, the two failures get different answers, and the
     * distinction is about privacy rather than tidiness:
     *
     *   not featured  a private folder belonging to some user. 404 for
     *                 EVERYONE, admins included. Moderating a catalogue
     *                 does not include reading people's saved lists, and
     *                 this URL is not where that would happen anyway.
     *
     *   not public    a pack of ours, unfinished. 404 for a visitor, and
     *                 for an admin a working preview with a banner saying
     *                 nobody else can see this. Same rule the blog already
     *                 follows for an unpublished post.
     */
    /**
     * ── WHY THE PARAMETER ACCEPTS BOTH A MODEL AND A STRING ──────────────
     *
     * It used to be `string $pack`, chosen deliberately to keep route model
     * binding out of it. That intention was right and the mechanism was
     * wrong: Livewire does not decide whether to bind by looking at mount's
     * signature alone. Livewire\Drawer\ImplicitRouteBinding builds its list
     * of bindable parameters from the component's PUBLIC PROPERTIES first
     * (getPublicPropertyTypes) and merges the mount signature in after — and
     * this component has `public Pack $pack`, a route-bindable model under
     * the same name as the route parameter.
     *
     * So the binding ran anyway, through the property, and by the time
     * mount was called the route parameter was no longer the slug: it was a
     * Collection. A model handed to a parameter declared `string`, on a page
     * whose whole reason for taking a string was to avoid exactly that.
     *
     * Accepting both is the honest signature, because both really do arrive
     * depending on how the request got here. Nothing is given up by it: the
     * binding resolves ANY collection, including somebody's private folder,
     * and the three checks below are what turn that into a 404. They are
     * still the gate — they just no longer depend on the lookup being done
     * by hand.
     */
    public function mount(Pack|string $pack): void
    {
        $found = $pack instanceof Pack
            ? $pack
            : Pack::where('slug', $pack)->first();

        abort_unless($found, 404);

        // Somebody's private collection. Not ours to show, at any rank.
        abort_unless($found->is_featured, 404);

        $isAdmin = (bool) auth()->user()?->isAdmin();

        abort_unless($found->is_public || $isAdmin, 404);

        $this->pack = $found;
        $this->isHidden = ! $found->is_public;

        view()->share('seo', [
            'title' => $this->pack->name.' sound effects',
            'description' => $this->pack->description
                ?: sprintf('%s — a curated sound effect pack. Free to preview, cleared for commercial use.', $this->pack->name),
            'canonical' => route('packs.show', $this->pack),

            /*
             * ── A PREVIEW IS NOT A PAGE ──────────────────────────────────
             *
             * A hidden pack 404s for everybody who is not an admin, so a
             * crawler cannot reach this anyway and the practical risk is
             * small. It is still one line, and it closes the one way this
             * leaks: an admin, signed in on their own phone, sending the
             * link to somebody to look at. The blog already marks a draft
             * this way and there is no reason for the two to disagree.
             */
            'noindex' => $this->isHidden,

            'jsonld' => $this->breadcrumb(),
        ]);
    }

    /**
     * The trail Google draws instead of the bare URL in a result.
     *
     * It matches the breadcrumb printed above the title — Packs / this pack
     * — and that is the whole reason the markup is worth anything: somebody
     * can check it against what they can see. Describing a path the page
     * does not show would be describing a different site.
     *
     * The last item carries no URL, following Google's own examples: the
     * final crumb is where you already are.
     *
     * @return array<string, mixed>
     */
    protected function breadcrumb(): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                [
                    '@type' => 'ListItem',
                    'position' => 1,
                    'name' => 'Packs',
                    'item' => route('packs.index'),
                ],
                [
                    '@type' => 'ListItem',
                    'position' => 2,
                    'name' => $this->pack->name,
                ],
            ],
        ];
    }

    #[Computed]
    public function sounds()
    {
        return $this->pack->sounds()
            ->published()
            ->with(['files', 'user:id,name', 'tags:id,name', 'category:id,name'])
            ->get();
    }

    /** Total running time, which is what a buyer actually wants to know. */
    #[Computed]
    public function totalDuration(): string
    {
        $seconds = (int) round($this->sounds->sum('duration_ms') / 1000);

        if ($seconds < 60) {
            return $seconds.'s';
        }

        $minutes = intdiv($seconds, 60);

        return $minutes >= 60
            ? intdiv($minutes, 60).'h '.($minutes % 60).'m'
            : $minutes.'m '.($seconds % 60).'s';
    }

    public function title(): string
    {
        return $this->pack->name;
    }
}; ?>

<div class="mx-auto max-w-4xl">

    {{-- ══════════════════════════════════════════════════════════════
         HIDDEN, AND ONLY AN ADMIN IS HERE

         The page renders in full so the pack can be checked before it goes
         live — the point of a preview is seeing the real thing. The banner
         is the part that must be impossible to miss: without it this looks
         identical to a published pack, and "why is nobody visiting it"
         becomes a question with no visible answer.
         ══════════════════════════════════════════════════════════════ --}}
    @if ($isHidden)
        <div class="mb-6 flex flex-wrap items-center gap-3 rounded-card bg-warning/[0.08] px-5 py-4">
            <x-icon name="eye-slash" style="solid" class="text-[0.85rem] text-warning" />
            <span class="text-[0.9rem]">
                This pack is <strong>not public</strong>. You can see it because you are an admin —
                everybody else gets a 404, and it does not appear on the packs page.
            </span>
            <a href="{{ route('admin.packs.edit', $pack) }}" wire:navigate
               class="ml-auto inline-flex items-center gap-2 rounded-full bg-ink px-4 py-2 text-[0.8rem] text-paper transition hover:-translate-y-0.5 dark:bg-paper/10">
                Publish it
                <x-icon name="arrow-right" style="solid" class="text-[0.66rem]" />
            </a>
        </div>
    @endif

    <nav class="mb-6 flex items-center gap-2 text-sm text-ink/50 dark:text-paper/50">
        <a href="{{ route('packs.index') }}" wire:navigate class="transition hover:text-brand">Packs</a>
        <x-icon name="chevron-right" style="solid" class="text-[0.6rem] opacity-50" />
        <span>{{ $pack->name }}</span>
    </nav>

    <div class="rise relative overflow-hidden rounded-panel bg-ink p-8 text-paper shadow-soft-lg sm:p-10 dark:bg-surface-dark">
        {{-- Masked toward the top-left, where the title and description are.
             Same reason as on x-tile: corner texture that dissolves before
             it reaches the text cannot collide with a long pack name.

             ── ONLY WHEN THERE IS A REAL COVER ───────────────────────────
             A pack without one now draws this same box-open glyph in the
             thumbnail beside the title, and the corner texture would be the
             second copy of it in one panel — a decoration that repeats the
             subject instead of sitting behind it. With a photograph up
             there the texture has nothing to compete with, so it stays. --}}
        @if ($pack->hasCover())
            <span aria-hidden="true"
                  style="mask-image: linear-gradient(to top left, #000 15%, transparent 78%);
                         -webkit-mask-image: linear-gradient(to top left, #000 15%, transparent 78%);"
                  class="pointer-events-none absolute -bottom-8 -right-7 text-[150px] leading-none text-paper/[0.05]">
                <x-icon name="box-open" style="solid" class="leading-none" />
            </span>
        @endif

        <div class="relative">
            {{-- ── THE FACE AND THE NAME, ON ONE LINE ────────────────────

                 items-center rather than items-start: the thumbnail is a
                 small square and the title is one or two lines, so aligning
                 their tops leaves the square floating above a short name.
                 Centred, the two read as one object at every length.

                 shrink-0 on the cover and min-w-0 on the text is the pair
                 that makes a long pack name wrap instead of squeezing the
                 square into a rectangle. --}}
            <div class="flex items-center gap-5">
                <x-pack-cover :pack="$pack" glyph="1.6rem"
                              class="size-20 shrink-0 rounded-[20px] shadow-soft-md sm:size-24" />

                <div class="min-w-0">
                    <div class="micro !text-paper/40">Pack</div>
                    <h1 class="mt-2 text-[clamp(1.8rem,4vw,2.6rem)] font-semibold">{{ $pack->name }}</h1>
                </div>
            </div>

            {{-- Full width, not indented under the title: a description set
                 in a column the width of what is left beside a thumbnail is
                 a ragged column for no reason. --}}
            @if ($pack->description)
                <p class="mt-5 max-w-[58ch] text-[0.98rem] leading-relaxed text-paper/65">{{ $pack->description }}</p>
            @endif

            <div class="mt-7 flex flex-wrap items-center gap-x-6 gap-y-3">
                <span class="flex items-center gap-2 text-[0.88rem] text-paper/65">
                    <x-icon name="waveform-lines" style="solid" class="text-[0.8rem] text-paper/30" />
                    {{ $this->sounds->count() }} {{ Str::plural('sound', $this->sounds->count()) }}
                </span>

                <span class="flex items-center gap-2 text-[0.88rem] text-paper/65">
                    <x-icon name="clock" style="solid" class="text-[0.8rem] text-paper/30" />
                    {{ $this->totalDuration }} in total
                </span>

                <div class="ml-auto">
                    <x-share-menu :url="route('packs.show', $pack)" :title="$pack->name" tone="dark" />
                </div>
            </div>
        </div>
    </div>

    <div class="mt-8 rounded-card bg-surface p-3 shadow-soft-md dark:bg-surface-dark">
        @forelse ($this->sounds as $sound)
            <x-sound-row :sound="$sound" :bars="80" wire:key="pack-sound-{{ $sound->id }}" />
        @empty
            <div class="py-16 text-center">
                <p class="text-[0.95rem] text-ink/50 dark:text-paper/50">This pack is still being filled.</p>
            </div>
        @endforelse
    </div>
</div>
