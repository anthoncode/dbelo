<?php

use App\Models\Category;
use App\Models\Sound;
use App\Models\Tag;
use App\Jobs\ProcessSoundUpload;
use App\Jobs\SuggestSoundMetadata;
use App\Notifications\SoundReviewed;
use App\Support\AutoTags;
use App\Support\Suggestions;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts.site')] #[Title('Moderation')] class extends Component {

    #[Url(except: 'pending')]
    public string $tab = 'pending';

    /** Per-row edits, keyed by sound id. Moderating usually means fixing a
     *  sloppy title or a missing category, not just saying yes or no. */
    public array $drafts = [];

    public ?int $rejecting = null;

    public string $reason = '';

    /**
     * The row whose Save was pressed last, if its fields have not been
     * touched since.
     *
     * ── WHY THE FLASH AT THE TOP WAS NOT ENOUGH ──────────────────────────
     *
     * refreshList() flashes a sentence that renders under the <h1>, four
     * hundred lines of markup above the button that triggered it. Livewire
     * re-renders in place and does not scroll, so on a long list the
     * confirmation appeared off-screen and the save looked like it had done
     * nothing.
     *
     * Worst on the Live tab, and by design: saveRow() deliberately does not
     * change the status, so a published row stays exactly where it was with
     * exactly the same values. There was no visible consequence at all. On
     * the review tab Approve removes the row from the tab, and that movement
     * is its own receipt.
     *
     * So the receipt now sits on the button that was pressed. The flash
     * stays for the actions that DO change the list — approve, reject,
     * retry, take offline — where a sentence at the top is the right place
     * to explain what moved.
     */
    public ?int $savedRow = null;

    /**
     * Which rows the batch buttons act on.
     *
     * @var array<int, bool>
     */
    public array $selected = [];

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $this->fillDrafts();
    }

    /**
     * Any edit to a draft retires the "Saved" badge.
     *
     * A receipt sitting next to a field somebody has retyped since is a
     * receipt that lies, and this screen's whole job is to let somebody
     * correct text before it goes out. Without this, the badge would claim
     * the new wording was stored the moment it was typed.
     *
     * The generic `updated` hook, deliberately NOT `updatedDrafts` — see the
     * warning on fillDrafts() below. A method named after a public property
     * is a name the framework has already taken.
     */
    public function updated(string $property): void
    {
        if (str_starts_with($property, 'drafts.')) {
            $this->savedRow = null;
        }
    }

    /**
     * Does this row's Save button currently read "Saved"?
     *
     * A plain public method rather than a computed property, because it
     * takes the row's id and a #[Computed] takes no arguments. Same idiom
     * the admin list uses for peaks($sound).
     *
     * It exists at all so the view does not need a one-line raw-PHP
     * directive to hold the comparison: this file already contains a block
     * one further down, and the two forms together are the pairing bug the
     * comment on the button describes.
     *
     * The directive names are spelled out nowhere in this docblock on
     * purpose. Blade runs its regexes over the WHOLE file, the class at the
     * top included, and the raw-PHP directives are ones it recognises — so
     * naming them inside a comment is enough for the compiler to act on
     * them and corrupt the block they sit in. php -l cannot see it, because
     * it lints this file rather than what Blade makes of it.
     */
    public function justSaved(int $id): bool
    {
        return $this->savedRow === $id;
    }

    public function updatedTab(): void
    {
        $this->rejecting = null;
        $this->selected = [];
        $this->savedRow = null;

        // The counts are per tab, so switching tab invalidates all of them.
        unset($this->sounds, $this->suggestedCount, $this->untaggedCount, $this->unsuggestedCount);

        $this->fillDrafts();
    }

    /**
     * Prime the per-row edit boxes.
     *
     * ── DO NOT CALL THIS hydrateDrafts ───────────────────────────────────
     * It was called that, and it took Approve down with
     * "Method hydrateDrafts does not exist" — on a method that plainly did.
     *
     * Livewire loops over the public properties on every request and calls
     * hydrate{Property} as a lifecycle hook, so a public $drafts makes the
     * name hydrateDrafts reserved. The existence check it runs is
     * method_exists(), which says yes to a protected method; the call goes
     * through the component's __call, which only dispatches public ones. So
     * the framework finds it and then refuses to call it, and the message
     * says the opposite of what is wrong.
     *
     * Same trap as Setting::cached() being deliberately not named all() or
     * get(): never take a name the framework already owns. Prefixes to
     * avoid alongside a matching property — hydrate, dehydrate, updating,
     * updated.
     */
    protected function fillDrafts(): void
    {
        foreach ($this->sounds as $sound) {
            $this->drafts[$sound->id] ??= [
                'title' => $sound->title,
                'category_id' => (string) ($sound->category_id ?? ''),
                'description' => (string) $sound->description,
                'tags' => $sound->tags->pluck('name')->join(', '),
                'is_premium' => (bool) $sound->is_premium,
                'is_featured' => (bool) $sound->is_featured,
            ];
        }
    }

    /* ═══════════════════ What the model suggested ═══════════════════ */

    /**
     * Copy a suggestion into the editable fields — WITHOUT saving.
     *
     * The two steps are deliberately separate. What comes back is a first
     * draft written by something that has not heard the audio, and the whole
     * reason it lands in a field instead of in the database is so it can be
     * read and corrected by somebody who has.
     *
     * A field that already has something in it is left alone. Overwriting a
     * description a person wrote with one a model guessed is the one move
     * this screen must never make on its own.
     */
    public function applySuggestion(int $id, bool $overwrite = false): bool
    {
        $sound = $this->sounds->firstWhere('id', $id) ?? Sound::with('tags')->find($id);

        if (! $sound || blank($sound->ai_suggestions)) {
            return false;
        }

        $suggestion = $sound->ai_suggestions;
        $draft = $this->drafts[$id] ?? [];

        if ($overwrite || trim((string) ($draft['description'] ?? '')) === '') {
            $draft['description'] = (string) ($suggestion['meta_description'] ?? '');
        }

        if ($overwrite || trim((string) ($draft['tags'] ?? '')) === '') {
            $draft['tags'] = implode(', ', $suggestion['tags'] ?? []);
        }

        if ($overwrite || ($draft['category_id'] ?? '') === '') {
            $categoryId = $this->categoryIdFromSlug($suggestion['category'] ?? null);

            if ($categoryId) {
                $draft['category_id'] = (string) $categoryId;
            }
        }

        $this->drafts[$id] = $draft;

        return true;
    }

    /** The suggested category as an id, only if that slug is really ours. */
    private function categoryIdFromSlug(?string $slug): ?int
    {
        if (blank($slug)) {
            return null;
        }

        return $this->categories->firstWhere('slug', $slug)?->id;
    }

    /**
     * Save the fields of one row. Does NOT change its status.
     *
     * Separate from Publish because they are separate decisions, and a button
     * that quietly does both is how a half-checked description ends up live.
     */
    public function saveRow(int $id): void
    {
        $sound = Sound::findOrFail($id);

        $this->writeDraft($sound);

        $this->refreshList("“{$sound->title}” saved.");

        // AFTER refreshList: it is the one that would otherwise clear this,
        // since every other action on the page wants the badge gone.
        $this->savedRow = $id;
    }

    /**
     * Apply and save every selected row in one go.
     *
     * This is the button that makes a hundred sounds survivable: read a few,
     * satisfy yourself the tone is right, tick the rest and accept. Nothing
     * is published by it — the sounds stay exactly where they are, now with
     * a description.
     */
    public function acceptSelected(): void
    {
        $ids = array_keys(array_filter($this->selected));

        if ($ids === []) {
            session()->flash('moderated', 'Nothing was selected.');

            return;
        }

        $done = 0;

        foreach ($ids as $id) {
            if (! $this->applySuggestion((int) $id)) {
                continue;
            }

            $sound = Sound::find($id);

            if (! $sound) {
                continue;
            }

            $this->writeDraft($sound);
            $done++;
        }

        $this->selected = [];

        $this->refreshList(match ($done) {
            0 => 'None of those had a suggestion to accept.',
            1 => 'One sound updated. Still unpublished — publish when you are ready.',
            default => "{$done} sounds updated. Still unpublished — publish when you are ready.",
        });
    }

    /** Tick every row on screen that has a suggestion waiting. */
    public function selectSuggested(): void
    {
        $this->selected = [];

        foreach ($this->sounds as $sound) {
            if (filled($sound->ai_suggestions)) {
                $this->selected[$sound->id] = true;
            }
        }
    }

    public function clearSelection(): void
    {
        $this->selected = [];
    }

    /** Ask for a suggestion for one sound, now. */
    public function suggest(int $id): void
    {
        if (! Suggestions::ready()) {
            session()->flash('moderated', 'No API key yet — Settings → Suggestions.');

            return;
        }

        SuggestSoundMetadata::dispatch($id);

        session()->flash('moderated', 'Queued. It appears here once the worker gets to it.');
    }

    /**
     * Write one row's draft onto the sound. Fields only, never the status.
     */
    private function writeDraft(Sound $sound): void
    {
        $draft = $this->drafts[$sound->id] ?? [];

        $title = trim($draft['title'] ?? $sound->title);

        $sound->fill([
            'title' => $title !== '' ? $title : $sound->title,
            'category_id' => $draft['category_id'] ?: null,
            'description' => trim((string) ($draft['description'] ?? '')) ?: null,
            'is_premium' => (bool) ($draft['is_premium'] ?? false),
            'is_featured' => (bool) ($draft['is_featured'] ?? false),
        ])->save();

        // Tag::idsFromList does the slugging, the de-duplication and the cap,
        // so the same list typed here and in the sound editor produces the
        // same tags. One definition, two surfaces.
        $sound->tags()->sync(Tag::idsFromList($draft['tags'] ?? null, Tag::MAX_PER_SOUND));
    }

    #[Computed]
    public function categories()
    {
        return Category::orderBy('parent_id')->orderBy('sort_order')->get();
    }

    public function retry(int $id): void
    {
        $sound = Sound::findOrFail($id);
        $sound->update(['processing_error' => null, 'status' => 'draft']);

        ProcessSoundUpload::dispatch($sound);

        $this->refreshList("“{$sound->title}” is back in the queue.");
    }

    #[Computed]
    public function sounds()
    {
        // The failed tab is not a status: it is any sound carrying an error,
        // whatever state it ended up in.
        if ($this->tab === 'failed') {
            return Sound::query()
                ->whereNotNull('processing_error')
                ->with(['files', 'category', 'user', 'tags'])
                ->latest('created_at')
                ->limit(40)
                ->get();
        }

        return Sound::query()
            ->where('status', $this->tab)
            ->with(['files', 'category', 'user', 'tags'])
            ->latest('created_at')
            ->limit(40)
            ->get();
    }

    /** How many rows on screen have a suggestion nobody has dealt with. */
    #[Computed]
    public function suggestedCount(): int
    {
        return $this->sounds->filter(fn ($sound) => filled($sound->ai_suggestions))->count();
    }

    /* ═══════════════════ The state of the whole tab ═══════════════════
     *
     * COUNTED ACROSS THE TAB, NOT ACROSS THE PAGE.
     *
     * The list shows forty at a time. A bar that said "12 untagged" while
     * meaning "12 of the forty you can see" would be read as the size of the
     * job, and the job would then appear to not finish.
     * ═════════════════════════════════════════════════════════════════ */

    /** The query the numbers and the batch buttons both work from. */
    private function tabQuery()
    {
        return Sound::query()
            ->when($this->tab === 'failed', fn ($q) => $q->whereNotNull('processing_error'))
            ->when($this->tab !== 'failed', fn ($q) => $q->where('status', $this->tab));
    }

    #[Computed]
    public function untaggedCount(): int
    {
        return $this->tabQuery()->has('tags', '<', AutoTags::MINIMUM)->count();
    }

    #[Computed]
    public function unsuggestedCount(): int
    {
        return $this->tabQuery()->whereNull('ai_suggested_at')->count();
    }

    /**
     * Tag everything in this tab from titles and categories.
     *
     * No API key, no queue, no cost — the words are already in the database.
     * Runs in the request because it is a few hundred small queries and the
     * answer has to be visible on the next render; the moment a catalogue is
     * big enough for that to be untrue, this becomes the command.
     */
    public function tagAll(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $sounds = $this->tabQuery()
            ->with(['tags', 'category.parent', 'user'])
            ->has('tags', '<', AutoTags::MINIMUM)
            ->limit(500)
            ->get();

        $added = 0;
        $touched = 0;

        foreach ($sounds as $sound) {
            $new = AutoTags::topUp($sound);

            if ($new > 0) {
                $added += $new;
                $touched++;
            }
        }

        $this->refreshList($touched === 0
            ? 'Nothing to tag — every sound here already has tags.'
            : "{$touched} sounds tagged, {$added} tags added.");
    }

    /**
     * Queue a suggestion for everything in this tab that has never had one.
     *
     * Capped, because this is the button that spends the quota. Two hundred
     * is more than a day's uploads and far less than a free tier's daily
     * allowance, so it cannot empty the budget by being clicked twice.
     */
    public function suggestAll(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        if (! Suggestions::ready()) {
            session()->flash('moderated', 'No API key yet — Admin → Settings → Suggestions.');

            return;
        }

        $ids = $this->tabQuery()
            ->whereNull('ai_suggested_at')
            ->orderBy('id')
            ->limit(200)
            ->pluck('id');

        foreach ($ids as $id) {
            SuggestSoundMetadata::dispatch($id);
        }

        $this->refreshList($ids->isEmpty()
            ? 'Every sound here has already been asked about.'
            : $ids->count().' queued. They fill in as the worker gets through them — the queue has to be running.');
    }

    #[Computed]
    public function counts(): array
    {
        $counts = Sound::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $counts['failed'] = Sound::whereNotNull('processing_error')->count();

        return $counts;
    }

    public function approve(int $id): void
    {
        $sound = Sound::findOrFail($id);
        $draft = $this->drafts[$id] ?? [];

        $title = trim($draft['title'] ?? $sound->title);

        if ($title === '') {
            $this->addError("drafts.{$id}.title", 'A title is required.');

            return;
        }

        // The slug only changes while the sound has never been public, so a
        // published URL never breaks under someone who bookmarked it.
        if ($title !== $sound->title && ! $sound->published_at) {
            $sound->slug = app(\App\Services\SoundImporter::class)->uniqueSlug($title);
        }

        // The editable fields, including the description and the tags, go
        // through the same writer the batch button uses — otherwise Publish
        // would quietly drop whatever was typed into the two fields it does
        // not happen to list.
        $this->writeDraft($sound);

        $sound->fill([
            'title' => $title,
            'status' => 'published',
            'published_at' => $sound->published_at ?? now(),
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
            'rejection_reason' => null,
        ])->save();

        // Only tell someone else's contributor: no point emailing yourself.
        if ($sound->user_id !== auth()->id()) {
            $sound->user->notify(new SoundReviewed($sound, 'published'));
        }

        $this->refreshList("“{$sound->title}” is live.");
    }

    public function startReject(int $id): void
    {
        $this->rejecting = $id;
        $this->reason = '';
    }

    public function cancelReject(): void
    {
        $this->rejecting = null;
        $this->reason = '';
    }

    public function reject(int $id): void
    {
        $this->validate([
            // Without a reason the contributor re-uploads the same thing and
            // you both waste the round trip.
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ], [
            'reason.required' => 'Explain why, so the contributor can fix it.',
            'reason.min' => 'Give at least a short sentence.',
        ]);

        $sound = Sound::with('user')->findOrFail($id);

        $sound->update([
            'status' => 'rejected',
            'rejection_reason' => $this->reason,
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        if ($sound->user_id !== auth()->id()) {
            $sound->user->notify(new SoundReviewed($sound, 'rejected'));
        }

        $this->rejecting = null;
        $this->reason = '';

        $this->refreshList('Rejected.');
    }

    public function restore(int $id): void
    {
        Sound::findOrFail($id)->update([
            'status' => 'pending',
            'rejection_reason' => null,
        ]);

        $this->refreshList('Back in the queue.');
    }

    public function unpublish(int $id): void
    {
        Sound::findOrFail($id)->update(['status' => 'pending']);

        $this->refreshList('Taken offline.');
    }

    protected function refreshList(string $message): void
    {
        // Every computed the bar reads, or the numbers keep showing what was
        // true before the button was pressed — which reads as "it did
        // nothing" on an action that worked.
        unset($this->sounds, $this->counts, $this->suggestedCount, $this->untaggedCount, $this->unsuggestedCount);
        $this->fillDrafts();

        /*
         * The per-row badge belongs to one save and nothing else.
         *
         * Cleared here rather than in each caller, so an action added later
         * cannot leave a "Saved" sitting on a row it did not save. saveRow()
         * sets it again straight after calling this — the one exception, and
         * it is the one that earned the badge.
         */
        $this->savedRow = null;

        session()->flash('moderated', $message);
    }
}; ?>


<div>
    {{--
        The neon that marks a machine-written field.

        INLINE, not a Tailwind utility, and not app.css. Same reason the
        converter page keeps its success pulse here: an animation that has
        never been compiled does not exist in a stale stylesheet, and this one
        has no fallback that reads as anything — it simply would not happen,
        silently. This way `view:clear` is enough; no rebuild.

        @property is what lets an ANGLE be animated. Without it a browser
        cannot interpolate `from 0deg` to `from 360deg` inside a gradient, and
        the border would sit still. Where it is unsupported that is exactly
        what happens: a static brand-coloured edge, which still says "this came
        from the model". Degrade, never break.
    --}}
    <style>
        @property --dbelo-ai-angle {
            syntax: '<angle>';
            initial-value: 0deg;
            inherits: false;
        }

        @keyframes dbelo-ai-turn {
            to { --dbelo-ai-angle: 360deg; }
        }

        .ai-neon { position: relative; }

        /* The border is a masked pseudo-element rather than a real border:
           a conic gradient cannot be a border-color, and painting the ring
           this way keeps the corner radius honest. */
        .ai-neon::before {
            content: "";
            position: absolute;
            inset: 0;
            border-radius: inherit;
            padding: 1.5px;
            background: conic-gradient(
                from var(--dbelo-ai-angle),
                rgba(138, 67, 253, 0) 0deg 200deg,
                rgba(138, 67, 253, 0.85) 285deg,
                rgba(249, 81, 15, 0.95) 330deg,
                rgba(138, 67, 253, 0) 360deg
            );
            -webkit-mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
            -webkit-mask-composite: xor;
            mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
            mask-composite: exclude;
            animation: dbelo-ai-turn 6s linear infinite;
            pointer-events: none;
        }

        /* Asked for less motion: the ring stays, the travel stops. Somebody
           who turned animation off still needs to see which field is a guess. */
        @media (prefers-reduced-motion: reduce) {
            .ai-neon::before { animation: none; }
        }
    </style>

    <div class="mx-auto max-w-5xl">

        <div class="mb-7">
            <div class="micro">Admin</div>
            <h1 class="mt-2 text-3xl font-semibold">Moderation</h1>
            <p class="mt-2 text-ink/60 dark:text-paper/60">
                Review what contributors upload before it reaches the catalogue.
            </p>
        </div>

        @if (session('moderated'))
            <div class="mb-6 flex items-center gap-3 rounded-card bg-surface p-4 shadow-soft-md dark:bg-surface-dark">
                <span class="grid size-9 shrink-0 place-items-center rounded-[12px] bg-brand text-white">
                    <x-icon name="check" style="solid" class="text-sm" />
                </span>
                <span class="text-[0.95rem]">{{ session('moderated') }}</span>
            </div>
        @endif

        {{-- Tabs --}}
        <div class="mb-6 flex flex-wrap gap-2">
            @foreach ([
                'pending' => 'In review',
                'published' => 'Live',
                'rejected' => 'Rejected',
                'processing' => 'Processing',
                'failed' => 'Failed',
            ] as $key => $label)
                <button wire:click="$set('tab', '{{ $key }}')"
                        class="flex items-center gap-2 rounded-full px-5 py-2.5 text-[0.85rem] shadow-soft-sm transition duration-300 ease-dbelo hover:-translate-y-0.5
                               {{ $tab === $key ? 'bg-ink text-paper dark:bg-brand' : 'bg-surface text-ink/60 dark:bg-surface-dark dark:text-paper/60' }}">
                    {{ $label }}
                    <span class="rounded-full px-2 py-0.5 text-[0.72rem] {{ $tab === $key ? 'bg-paper/20' : 'bg-ink/[0.06] dark:bg-paper/10' }}">
                        {{ $this->counts[$key] ?? 0 }}
                    </span>
                </button>
            @endforeach
        </div>

        {{-- ══════════════════════════════════════════════════════════════
             THE BATCH BAR

             Only appears when there is something to batch. A toolbar that is
             always there but usually does nothing trains you to stop reading
             it, and then it is furniture on the day it matters.
             ══════════════════════════════════════════════════════════════ --}}
        @if ($this->untaggedCount > 0 || $this->unsuggestedCount > 0 || $this->suggestedCount > 0)
            <div class="mb-6 rounded-card bg-surface p-5 shadow-soft-md dark:bg-surface-dark">
                <div class="flex flex-wrap items-start gap-4">
                    <span class="grid size-9 shrink-0 place-items-center rounded-[12px] bg-brand/10 text-brand">
                        <x-icon name="wand-magic-sparkles" style="solid" class="text-sm" />
                    </span>

                    <div class="min-w-0 flex-1">
                        {{-- The numbers first, and for the WHOLE tab rather
                             than the forty on screen. "Nothing has tags" is a
                             state this screen has to be able to state out
                             loud — the version of it that said nothing is why
                             an empty catalogue looked like a broken one. --}}
                        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-[0.95rem]">
                            @if ($this->untaggedCount > 0)
                                <span class="flex items-center gap-2">
                                    <x-icon name="tag" style="solid" class="text-[0.75rem] text-warning" />
                                    {{ $this->untaggedCount }} with fewer than {{ \App\Support\AutoTags::MINIMUM }} tags
                                </span>
                            @endif

                            @if ($this->unsuggestedCount > 0)
                                <span class="flex items-center gap-2">
                                    <x-icon name="sparkles" style="solid" class="text-[0.75rem] text-info" />
                                    {{ $this->unsuggestedCount }} never sent to the AI
                                </span>
                            @endif

                            @if ($this->suggestedCount > 0)
                                <span class="flex items-center gap-2">
                                    <x-icon name="inbox" style="solid" class="text-[0.75rem] text-success" />
                                    {{ $this->suggestedCount }} with a suggestion on this page
                                </span>
                            @endif
                        </div>

                        <p class="micro mt-2 max-w-[80ch]">
                            Tagging is free and instant — the words come from the title and the category, nothing is
                            invented. Asking the AI spends quota and runs on the queue. Neither one publishes anything,
                            and neither one overwrites a field you filled in yourself.
                        </p>
                    </div>
                </div>

                <div class="mt-4 flex flex-wrap items-center gap-3">
                    @if ($this->untaggedCount > 0)
                        <button wire:click="tagAll" wire:loading.attr="disabled" wire:target="tagAll"
                                class="flex items-center gap-2 rounded-full bg-ink px-5 py-2.5 text-[0.85rem] text-paper shadow-soft-sm transition hover:-translate-y-0.5 disabled:opacity-50 dark:bg-paper/10 dark:text-paper">
                            <x-icon name="tag" style="solid" class="text-xs" wire:loading.remove wire:target="tagAll" />
                            <x-icon name="spinner-third" style="solid" class="animate-spin text-xs" wire:loading wire:target="tagAll" />
                            Tag {{ $this->untaggedCount }} from their titles
                        </button>
                    @endif

                    @if ($this->unsuggestedCount > 0)
                        <button wire:click="suggestAll" wire:loading.attr="disabled" wire:target="suggestAll"
                                class="flex items-center gap-2 rounded-full bg-paper px-5 py-2.5 text-[0.85rem] shadow-soft-sm transition hover:-translate-y-0.5 disabled:opacity-50 dark:bg-paper/10">
                            <x-icon name="sparkles" style="solid" class="text-xs" wire:loading.remove wire:target="suggestAll" />
                            <x-icon name="spinner-third" style="solid" class="animate-spin text-xs" wire:loading wire:target="suggestAll" />
                            Ask the AI about {{ min(200, $this->unsuggestedCount) }}
                        </button>
                    @endif

                    @if ($this->suggestedCount > 0)
                        <div class="ml-auto flex flex-wrap items-center gap-3">
                            @if (count(array_filter($selected)) > 0)
                                <button wire:click="clearSelection"
                                        class="px-3 text-sm text-ink/50 underline dark:text-paper/50">Clear</button>

                                <button wire:click="acceptSelected" wire:loading.attr="disabled"
                                        class="flex items-center gap-2 rounded-full bg-brand px-6 py-2.5 text-[0.85rem] font-medium text-white shadow-brand transition duration-300 ease-dbelo hover:-translate-y-0.5 hover:shadow-brand-lg">
                                    <x-icon name="check" style="solid" class="text-xs" />
                                    Accept {{ count(array_filter($selected)) }} descriptions
                                </button>
                            @else
                                <button wire:click="selectSuggested"
                                        class="rounded-full bg-paper px-5 py-2.5 text-[0.85rem] shadow-soft-sm transition hover:-translate-y-0.5 dark:bg-paper/10">
                                    Select all {{ $this->suggestedCount }}
                                </button>
                            @endif
                        </div>
                    @endif
                </div>

                {{-- The one thing that makes "Ask the AI" do nothing, said
                     before it is pressed rather than after. --}}
                @if ($this->unsuggestedCount > 0 && ! \App\Support\Suggestions::ready())
                    <p class="mt-3 flex items-center gap-2 text-[0.83rem] text-warning">
                        <x-icon name="triangle-exclamation" style="solid" class="text-[0.75rem]" />
                        No API key yet, so only tagging will work.
                        <a href="{{ route('admin.settings.suggestions') }}" wire:navigate class="underline underline-offset-2">Settings → Suggestions</a>
                    </p>
                @endif
            </div>
        @endif

        {{-- Queue --}}
        <div class="space-y-4">
            @forelse ($this->sounds as $sound)
                <div wire:key="mod-{{ $sound->id }}" class="rounded-card bg-surface p-6 shadow-soft-md dark:bg-surface-dark">

                    {{-- ══════════════════════════════════════════════════
                         WHAT THIS ONE NEEDS FROM YOU
                         ══════════════════════════════════════════════════

                         Everything these badges say was already on the card
                         — in the counters at the top, in the box under the
                         player, in the absence of tags on the right. It was
                         just spread across three places, so answering "what
                         does this row want" meant reading the whole card.

                         They describe an ACTION OUTSTANDING, never a state.
                         "In review" is not here: that is the tab you are
                         already looking at, and a badge repeating the tab is
                         a badge nobody reads after the first day.

                         Each one is drawn only when it is true, so a row
                         with nothing pending carries no badges at all — and
                         a clean row should look clean. --}}
                    @php
                        $hasSuggestion = filled($sound->ai_suggestions);
                        $neverAsked = $sound->ai_suggested_at === null;
                        $noTags = $sound->tags->isEmpty();
                    @endphp

                    @if ($hasSuggestion || $neverAsked || $noTags)
                        <div class="mb-4 flex flex-wrap items-center gap-2">

                            {{-- The only one with something to accept. Brand
                                 colour because it is the row's reason for
                                 being on this screen. --}}
                            @if ($hasSuggestion)
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-brand/12 px-2.5 py-1 text-[0.72rem] font-medium text-brand">
                                    <x-icon name="wand-magic-sparkles" style="solid" class="text-[0.62rem]" />
                                    Suggestion to review
                                </span>
                            @endif

                            {{-- A sound with no tags is a sound the search
                                 barely finds — the one real defect of the
                                 three, so it is the one in warning. --}}
                            @if ($noTags)
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-warning/15 px-2.5 py-1 text-[0.72rem] font-medium text-warning">
                                    <x-icon name="tag" style="solid" class="text-[0.62rem]" />
                                    No tags
                                </span>
                            @endif

                            {{-- Muted on purpose: it is not a fault, it is a
                                 thing that has not happened. Usually because
                                 the key was missing when it was uploaded. --}}
                            @if ($neverAsked)
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-ink/[0.06] px-2.5 py-1 text-[0.72rem] text-ink/45 dark:bg-paper/10 dark:text-paper/45">
                                    <x-icon name="robot" style="regular" class="text-[0.62rem]" />
                                    Never sent to the AI
                                </span>
                            @endif
                        </div>
                    @endif

                    <div class="mb-5 flex flex-wrap items-start justify-between gap-4">
                        <div class="micro">
                            by {{ $sound->user->name }} · {{ $sound->created_at->diffForHumans() }}
                            @if ($sound->duration_ms) · {{ $sound->durationForHumans() }} @endif
                            @if ($sound->sample_rate) · {{ number_format($sound->sample_rate / 1000, 1) }} kHz @endif
                            @if ($sound->channels) · {{ $sound->channels === 1 ? 'Mono' : 'Stereo' }} @endif
                        </div>

                        @if ($sound->tags->isNotEmpty())
                            <div class="flex flex-wrap gap-1.5">
                                @foreach ($sound->tags as $tag)
                                    <span class="rounded-full bg-paper px-3 py-1 text-[0.75rem] text-ink/60 dark:bg-paper/10 dark:text-paper/60">{{ $tag->name }}</span>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    {{-- Listen before deciding --}}
                    <x-waveform-player :sound="$sound" :bars="110" height="h-14" class="mb-6" />

                    @if ($sound->processing_error)
                        <div class="mb-5 flex flex-wrap items-center gap-3 rounded-control bg-brand/10 px-4 py-3 text-sm text-brand">
                            <x-icon name="triangle-exclamation" style="solid" />
                            <span class="min-w-0 flex-1">{{ $sound->processing_error }}</span>
                            <button wire:click="retry({{ $sound->id }})"
                                    class="shrink-0 rounded-full bg-brand px-4 py-2 text-[0.8rem] font-medium text-white transition hover:-translate-y-0.5">
                                Retry
                            </button>
                        </div>
                    @endif

                    @if ($sound->status === 'rejected' && $sound->rejection_reason)
                        <div class="mb-5 rounded-control bg-ink/[0.05] px-4 py-3 text-sm dark:bg-paper/10">
                            <span class="micro block mb-1">Reason given</span>
                            {{ $sound->rejection_reason }}
                        </div>
                    @endif

                    {{-- ══════════════════════════════════════════════════
                         WHAT THE MODEL SUGGESTED

                         Shown NEXT TO the fields rather than inside them, so
                         what a person wrote and what a model guessed never
                         look alike. The model has not heard the audio — it
                         read a filename and a duration — so this is a draft
                         with a source attached, not an answer.
                         ══════════════════════════════════════════════════ --}}
                    @if (filled($sound->ai_suggestions))
                        {{-- The ring goes round THIS box, not round the card.

                             What the model wrote is a sentence, not a sound.
                             A neon border on the whole card would label the
                             upload as machine-made when a person recorded
                             it, named it and is about to approve it — and it
                             would fight the card's own job, which is the
                             verdict at the bottom. Marking the field says
                             the true thing in the place it is true. --}}
                        <div class="ai-neon relative mb-5 mt-4 rounded-control bg-brand/[0.06] p-4 pt-5">

                            {{-- The chip, sitting on the edge. Round, so it
                                 reads as a stamp rather than another of the
                                 pill-shaped labels this screen already uses
                                 for tags and status. --}}
                            <span class="absolute -top-3 left-4 grid size-8 place-items-center rounded-full bg-brand text-[0.66rem] font-bold tracking-[0.04em] text-white shadow-brand"
                                  title="Written by the model, not by a person">
                                AI
                            </span>

                            <div class="mb-3 flex flex-wrap items-center gap-3">
                                <label class="flex cursor-pointer items-center gap-2.5 text-[0.85rem]">
                                    <input type="checkbox" wire:model.live="selected.{{ $sound->id }}"
                                           class="size-4 rounded border-0 bg-paper text-brand focus:ring-brand/30 dark:bg-paper/20" />
                                    <span class="micro !text-brand">
                                        Suggested by {{ $sound->ai_provider ?: 'AI' }}
                                        @if ($sound->ai_suggested_at) · {{ $sound->ai_suggested_at->diffForHumans() }} @endif
                                    </span>
                                </label>

                                <button wire:click="applySuggestion({{ $sound->id }})"
                                        class="ml-auto rounded-full bg-brand px-4 py-1.5 text-[0.78rem] font-medium text-white transition hover:-translate-y-0.5">
                                    Use the description
                                </button>

                                <button wire:click="applySuggestion({{ $sound->id }}, true)"
                                        class="text-[0.78rem] text-ink/45 underline underline-offset-2 transition hover:text-brand dark:text-paper/45">
                                    Replace everything
                                </button>
                            </div>

                            @if (! empty($sound->ai_suggestions['meta_description']))
                                <p class="text-[0.9rem] leading-relaxed text-ink/70 dark:text-paper/70">
                                    {{ $sound->ai_suggestions['meta_description'] }}
                                </p>
                            @endif

                            <div class="mt-3 flex flex-wrap items-center gap-1.5">
                                @if (! empty($sound->ai_suggestions['category']))
                                    <span class="rounded-full bg-ink/[0.06] px-2.5 py-1 text-[0.72rem] text-ink/60 dark:bg-paper/10 dark:text-paper/60">
                                        <x-icon name="folder-tree" style="solid" class="mr-1 text-[0.62rem]" />
                                        {{ $sound->ai_suggestions['category'] }}
                                    </span>
                                @endif

                                {{-- These are already on the sound. Saying so
                                     matters: a list that looks like the other
                                     pending suggestions but is in fact done
                                     has you waiting for a button that is not
                                     coming. --}}
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-success/10 px-2.5 py-1 text-[0.72rem] text-success">
                                    <x-icon name="check" style="solid" class="text-[0.62rem]" />
                                    Tags saved automatically
                                </span>

                                @foreach ($sound->ai_suggestions['tags'] ?? [] as $suggestedTag)
                                    <span class="rounded-full bg-paper px-2.5 py-1 text-[0.72rem] text-ink/60 dark:bg-paper/10 dark:text-paper/60">{{ $suggestedTag }}</span>
                                @endforeach
                            </div>

                            {{-- The Spanish terms, in a different colour
                                 because they behave differently: they are
                                 matched by the search and never rendered on
                                 the site. Shown here so a wrong one can be
                                 spotted; they are already saved. --}}
                            @if (filled($sound->search_terms))
                                <div class="mt-2 flex flex-wrap items-center gap-1.5">
                                    <span class="micro !text-info mr-1">ES</span>
                                    @foreach ($sound->search_terms as $term)
                                        <span class="rounded-full bg-info/10 px-2.5 py-1 text-[0.72rem] text-info">{{ $term }}</span>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @elseif (in_array($sound->status, ['pending', 'draft', 'published'], true))
                        <div class="mb-5 flex flex-wrap items-center gap-3 rounded-control bg-ink/[0.03] px-4 py-2.5 dark:bg-paper/[0.04]">
                            <span class="micro">No suggestion yet</span>
                            <button wire:click="suggest({{ $sound->id }})"
                                    class="ml-auto text-[0.78rem] text-ink/45 underline underline-offset-2 transition hover:text-brand dark:text-paper/45">
                                Ask for one
                            </button>
                        </div>
                    @endif

                    {{-- Editable fields --}}
                    <div class="grid gap-4 md:grid-cols-[1fr_240px]">
                        <label class="block">
                            <span class="micro mb-2 block">Title</span>
                            <input type="text" wire:model="drafts.{{ $sound->id }}.title"
                                   class="w-full rounded-control border-0 bg-paper px-4 py-2.5 text-[0.95rem] shadow-soft-sm focus:outline-none focus:ring-2 focus:ring-brand/30 dark:bg-paper/10" />
                            @error("drafts.{$sound->id}.title") <span class="mt-1 block text-sm text-brand">{{ $message }}</span> @enderror
                        </label>

                        <label class="block">
                            <span class="micro mb-2 block">Category</span>
                            <select wire:model="drafts.{{ $sound->id }}.category_id"
                                    class="w-full rounded-control border-0 bg-paper px-4 py-2.5 text-[0.95rem] shadow-soft-sm focus:outline-none focus:ring-2 focus:ring-brand/30 dark:bg-paper/10">
                                <option value="">Uncategorised</option>
                                @foreach ($this->categories as $cat)
                                    <option value="{{ $cat->id }}">{{ $cat->parent_id ? '— ' : '' }}{{ $cat->name }}</option>
                                @endforeach
                            </select>
                        </label>
                    </div>

                    <div class="mt-4 grid gap-4">
                        <label class="block">
                            <span class="micro mb-2 block">Description</span>
                            <textarea wire:model="drafts.{{ $sound->id }}.description" rows="2"
                                      placeholder="What the sound is, and what it is for."
                                      class="w-full rounded-control border-0 bg-paper px-4 py-2.5 text-[0.95rem] leading-relaxed shadow-soft-sm placeholder:text-ink/30 focus:outline-none focus:ring-2 focus:ring-brand/30 dark:bg-paper/10 dark:placeholder:text-paper/30"></textarea>
                        </label>

                        <label class="block">
                            <span class="micro mb-2 block">Tags</span>
                            <input type="text" wire:model="drafts.{{ $sound->id }}.tags"
                                   placeholder="thunder, storm, rumble, distant"
                                   class="w-full rounded-control border-0 bg-paper px-4 py-2.5 text-[0.95rem] shadow-soft-sm placeholder:text-ink/30 focus:outline-none focus:ring-2 focus:ring-brand/30 dark:bg-paper/10 dark:placeholder:text-paper/30" />
                        </label>
                    </div>

                    {{-- Actions --}}
                    <div class="mt-6 flex flex-wrap items-center gap-3">
                        <button wire:click="$toggle('drafts.{{ $sound->id }}.is_premium')"
                                class="flex items-center gap-2 rounded-full px-4 py-2.5 text-[0.83rem] shadow-soft-sm transition duration-300 ease-dbelo hover:-translate-y-0.5
                                       {{ ($drafts[$sound->id]['is_premium'] ?? false) ? 'bg-brand text-white' : 'bg-paper dark:bg-paper/10' }}">
                            <x-icon name="crown" :style="($drafts[$sound->id]['is_premium'] ?? false) ? 'solid' : 'regular'" class="text-xs" />
                            Premium
                        </button>

                        <button wire:click="$toggle('drafts.{{ $sound->id }}.is_featured')"
                                class="flex items-center gap-2 rounded-full px-4 py-2.5 text-[0.83rem] shadow-soft-sm transition duration-300 ease-dbelo hover:-translate-y-0.5
                                       {{ ($drafts[$sound->id]['is_featured'] ?? false) ? 'bg-brand text-white' : 'bg-paper dark:bg-paper/10' }}">
                            <x-icon name="star" :style="($drafts[$sound->id]['is_featured'] ?? false) ? 'solid' : 'regular'" class="text-xs" />
                            Featured
                        </button>

                        <div class="ml-auto flex flex-wrap gap-3">
                            {{-- Save without publishing. The two used to be
                                 one button, which meant the only way to keep
                                 a corrected description was to put the sound
                                 live at the same moment.

                                 THE BUTTON IS ITS OWN RECEIPT. The flash at
                                 the top of the page renders four hundred
                                 lines above this, and Livewire does not
                                 scroll — so on a long list a save looked like
                                 it had done nothing, worst of all on the Live
                                 tab where the row does not move afterwards.

                                 Still clickable while it says Saved: the
                                 state is "this is stored", not "this is
                                 finished". Disabling it would be a third
                                 meaning nobody asked for. Any edit to a field
                                 in this row turns it back into Save — see the
                                 `updated` hook.

                                 Through $this->justSaved() and NOT a one-line
                                 @ php(): this file already carries a block
                                 @ php … @ endphp further up, and Blade lifts
                                 raw PHP blocks before compiling and pairs the
                                 first opener it finds with the first closer.
                                 Mixing the two forms in one file took the
                                 sound page down once already.

                                 text-ink and not text-white: success is
                                 #89d206, a bright lime, and white on it is
                                 barely legible. Dark text on a bright fill is
                                 what the amber build banner does too. --}}
                            <button wire:click="saveRow({{ $sound->id }})" wire:loading.attr="disabled"
                                    @class([
                                        'flex items-center gap-2 rounded-full px-5 py-2.5 text-[0.85rem] shadow-soft-sm transition duration-300 ease-dbelo hover:-translate-y-0.5',
                                        'bg-success text-ink' => $this->justSaved($sound->id),
                                        'bg-paper dark:bg-paper/10' => ! $this->justSaved($sound->id),
                                    ])>
                                <x-icon :name="$this->justSaved($sound->id) ? 'check' : 'floppy-disk'" style="solid" class="text-xs" />
                                {{ $this->justSaved($sound->id) ? 'Saved' : 'Save' }}
                            </button>

                            <a href="{{ route('sounds.edit', $sound) }}" wire:navigate
                               class="flex items-center gap-2 rounded-full bg-paper px-5 py-2.5 text-[0.85rem] shadow-soft-sm transition hover:-translate-y-0.5 dark:bg-paper/10">
                                <x-icon name="pen" style="solid" class="text-xs" /> Edit
                            </a>

                            @if ($sound->status === 'published')
                                <a href="{{ route('sounds.show', $sound) }}" target="_blank"
                                   class="flex items-center gap-2 rounded-full bg-paper px-5 py-2.5 text-[0.85rem] shadow-soft-sm transition hover:-translate-y-0.5 dark:bg-paper/10">
                                    <x-icon name="arrow-up-right-from-square" style="solid" class="text-xs" /> View
                                </a>
                                <button wire:click="unpublish({{ $sound->id }})"
                                        class="rounded-full bg-paper px-5 py-2.5 text-[0.85rem] shadow-soft-sm transition hover:-translate-y-0.5 dark:bg-paper/10">
                                    Take offline
                                </button>
                            @elseif ($sound->status === 'rejected')
                                <button wire:click="restore({{ $sound->id }})"
                                        class="rounded-full bg-paper px-5 py-2.5 text-[0.85rem] shadow-soft-sm transition hover:-translate-y-0.5 dark:bg-paper/10">
                                    Back to review
                                </button>
                            @else
                                <button wire:click="startReject({{ $sound->id }})"
                                        class="rounded-full bg-paper px-5 py-2.5 text-[0.85rem] shadow-soft-sm transition hover:-translate-y-0.5 dark:bg-paper/10">
                                    Reject
                                </button>
                            @endif

                            @if ($sound->status !== 'published')
                                <button wire:click="approve({{ $sound->id }})" wire:loading.attr="disabled"
                                        class="flex items-center gap-2 rounded-full bg-brand px-6 py-2.5 text-[0.85rem] font-medium text-white shadow-brand transition duration-300 ease-dbelo hover:-translate-y-0.5 hover:shadow-brand-lg">
                                    <x-icon name="check" style="solid" class="text-xs" />
                                    Publish
                                </button>
                            @endif
                        </div>
                    </div>

                    {{-- Rejection reason --}}
                    @if ($rejecting === $sound->id)
                        <div class="mt-5 rounded-control bg-paper p-4 dark:bg-paper/10">
                            <span class="micro mb-2 block">Why are you rejecting this?</span>
                            <textarea wire:model="reason" rows="2" autofocus
                                      placeholder="Background noise throughout, and the file clips at 0:03."
                                      class="w-full rounded-control border-0 bg-surface px-4 py-3 text-[0.92rem] shadow-soft-sm placeholder:text-ink/30 focus:outline-none focus:ring-2 focus:ring-brand/30 dark:bg-surface-dark dark:placeholder:text-paper/30"></textarea>
                            @error('reason') <span class="mt-1 block text-sm text-brand">{{ $message }}</span> @enderror

                            <div class="mt-3 flex gap-3">
                                <button wire:click="reject({{ $sound->id }})"
                                        class="rounded-full bg-ink px-5 py-2.5 text-[0.85rem] text-paper shadow-soft-sm transition hover:-translate-y-0.5 dark:bg-brand">
                                    Confirm rejection
                                </button>
                                <button wire:click="cancelReject" class="px-3 text-sm text-ink/50 underline dark:text-paper/50">Cancel</button>
                            </div>
                        </div>
                    @endif
                </div>
            @empty
                <div class="rounded-card bg-surface py-20 text-center shadow-soft-md dark:bg-surface-dark">
                    <p class="text-lg">Nothing here</p>
                    <p class="micro mt-2">
                        {{ $tab === 'pending' ? 'The review queue is empty' : 'No sounds with this status' }}
                    </p>
                </div>
            @endforelse
        </div>
    </div>
</div>
