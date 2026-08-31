<?php

use App\Models\Claim;
use App\Models\Sound;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.site')] #[Title('Copyright complaint')] class extends Component {
    public string $sound_url = '';
    public string $claimant_name = '';
    public string $claimant_email = '';
    public string $claimant_organisation = '';
    public string $claimant_role = 'owner';
    public string $claimant_address = '';
    public string $right_claimed = 'copyright';
    public string $description = '';
    public string $evidence_url = '';
    public bool $sworn = false;

    /** Set after a successful submission: the reference the claimant keeps. */
    public ?string $reference = null;

    public function mount(): void
    {
        // Arriving from a sound page pre-fills the URL, which is the field
        // people get wrong most often.
        $this->sound_url = (string) request()->query('url', '');
    }

    /**
     * People paste the whole address of the page. Pull the slug out of it
     * rather than making them find an ID they have no reason to know.
     */
    protected function resolveSound(): ?Sound
    {
        $path = parse_url(trim($this->sound_url), PHP_URL_PATH) ?: trim($this->sound_url);
        $slug = basename(rtrim((string) $path, '/'));

        return $slug ? Sound::withTrashed()->where('slug', $slug)->first() : null;
    }

    public function submit(): void
    {
        $key = 'claim:'.request()->ip();

        // A takedown form with no limit is a denial-of-service tool aimed at
        // the catalogue.
        if (RateLimiter::tooManyAttempts($key, 3)) {
            $this->addError('sound_url', 'Too many complaints from this address today. Write to us instead.');

            return;
        }

        $this->validate([
            'sound_url' => ['required', 'string', 'max:300'],
            'claimant_name' => ['required', 'string', 'max:120'],
            'claimant_email' => ['required', 'email', 'max:255'],
            'claimant_organisation' => ['nullable', 'string', 'max:120'],
            'claimant_role' => ['required', 'in:owner,agent'],
            'claimant_address' => ['nullable', 'string', 'max:400'],
            'right_claimed' => ['required', 'in:copyright,trademark,voice,privacy,other'],
            'description' => ['required', 'string', 'min:40', 'max:3000'],
            'evidence_url' => ['nullable', 'url', 'max:300'],
            'sworn' => ['accepted'],
        ], [
            'description.min' => 'Tell us enough to identify the work and why it is yours — a line or two is not enough to act on.',
            'sworn.accepted' => 'You have to confirm the statement before we can accept the complaint.',
        ]);

        $sound = $this->resolveSound();

        if (! $sound) {
            $this->addError('sound_url', 'We could not find that sound. Copy the address from your browser bar on the sound page.');

            return;
        }

        RateLimiter::hit($key, 3600);

        $claim = Claim::create([
            'sound_id' => $sound->id,
            'contributor_id' => $sound->user_id,
            'claimant_name' => $this->claimant_name,
            'claimant_email' => $this->claimant_email,
            'claimant_organisation' => $this->claimant_organisation ?: null,
            'claimant_role' => $this->claimant_role,
            'claimant_address' => $this->claimant_address ?: null,
            'right_claimed' => $this->right_claimed,
            'description' => $this->description,
            'evidence_url' => $this->evidence_url ?: null,
            'sworn' => true,
            'status' => Claim::STATUS_NEW,
            'source' => 'form',
            // What is already out there. Licences granted before today are
            // never revoked, so this number is fixed from now on.
            'downloads_at_claim' => $sound->downloads_count,
            'ip_address' => request()->ip(),
        ]);

        // Deliberately NOT taking the sound down here. The Terms promise a
        // review first, and an automatic takedown would let anyone erase a
        // competitor's work by filling in a form.

        $this->reference = $claim->reference();
    }
}; ?>

<div>
    <div class="mx-auto max-w-3xl py-6">

        <div class="mb-9">
            <div class="micro">Legal</div>
            <h1 class="mt-2 text-3xl font-semibold">Copyright complaint</h1>
            <p class="mt-2 text-ink/60 dark:text-paper/60">
                If something on dbelo infringes your rights, tell us here and we will review it.
            </p>
        </div>

        @if ($reference)
            <div class="rounded-card bg-surface p-8 shadow-soft-md dark:bg-surface-dark">
                <div class="flex items-start gap-4">
                    <span class="grid size-11 shrink-0 place-items-center rounded-full bg-brand/15 text-brand">
                        <x-icon name="circle-check" style="solid" />
                    </span>

                    <div>
                        <h2 class="text-lg font-medium">We have your complaint</h2>

                        <p class="mt-2 leading-relaxed text-ink/70 dark:text-paper/70">
                            Your reference is
                            <strong class="font-mono text-ink dark:text-paper">{{ $reference }}</strong>.
                            Write it down — quote it if you need to follow up.
                        </p>

                        <p class="mt-3 leading-relaxed text-ink/70 dark:text-paper/70">
                            We review every complaint by hand. If it is credible we take the sound offline while
                            we investigate. We will reply to
                            <strong class="font-medium">{{ $claimant_email }}</strong>.
                        </p>

                        <p class="mt-3 text-[0.88rem] leading-relaxed text-ink/50 dark:text-paper/50">
                            One thing we cannot undo: people who downloaded the sound before today keep the licence
                            they were granted. Taking it offline stops new downloads — it does not reach into work
                            already published.
                        </p>

                        <a href="{{ route('sounds.index') }}" wire:navigate
                           class="mt-6 inline-flex rounded-full bg-brand px-6 py-2.5 text-[0.9rem] font-medium text-white shadow-brand transition duration-300 ease-dbelo hover:-translate-y-0.5">
                            Back to the catalogue
                        </a>
                    </div>
                </div>
            </div>
        @else
            <div class="rounded-card bg-surface p-8 shadow-soft-md dark:bg-surface-dark">

                <p class="mb-7 leading-relaxed text-ink/70 dark:text-paper/70">
                    This form is for rights holders and their authorised agents. Everything marked as required is
                    genuinely required: without it we cannot verify the claim, and a claim we cannot verify is one
                    we cannot act on.
                </p>

                {{-- Which sound --}}
                <div class="micro">The sound</div>

                <label class="mt-3 block">
                    <span class="mb-2 block text-[0.85rem] font-medium">Address of the sound page *</span>
                    <input type="text" wire:model="sound_url" placeholder="https://dbelo.com/sounds/door-creak-old-wood"
                           class="w-full rounded-control border border-ink/10 bg-paper px-4 py-3 text-[0.92rem] placeholder:text-ink/25 focus:border-brand focus:outline-none dark:border-paper/10 dark:bg-ink dark:placeholder:text-paper/25" />
                    @error('sound_url') <p class="mt-1.5 text-[0.82rem] text-danger">{{ $message }}</p> @enderror
                    <p class="mt-1.5 text-[0.8rem] text-ink/45 dark:text-paper/45">Copy it from your browser bar on the page of the sound.</p>
                </label>

                <label class="mt-5 block">
                    <span class="mb-2 block text-[0.85rem] font-medium">What right are you claiming? *</span>
                    <select wire:model="right_claimed"
                            class="w-full rounded-control border border-ink/10 bg-paper px-4 py-3 text-[0.92rem] focus:border-brand focus:outline-none dark:border-paper/10 dark:bg-ink">
                        <option value="copyright">Copyright — I own the recording or the work</option>
                        <option value="trademark">Trademark</option>
                        <option value="voice">Voice or likeness — that is me, and I did not consent</option>
                        <option value="privacy">Privacy — it was recorded where it should not have been</option>
                        <option value="other">Something else</option>
                    </select>
                </label>

                <label class="mt-5 block">
                    <span class="mb-2 block text-[0.85rem] font-medium">Explain the claim *</span>
                    <textarea wire:model="description" rows="5"
                              placeholder="What the work is, when and where you published it, and why the sound on dbelo infringes it."
                              class="w-full resize-none rounded-control border border-ink/10 bg-paper px-4 py-3 text-[0.92rem] placeholder:text-ink/25 focus:border-brand focus:outline-none dark:border-paper/10 dark:bg-ink dark:placeholder:text-paper/25"></textarea>
                    @error('description') <p class="mt-1.5 text-[0.82rem] text-danger">{{ $message }}</p> @enderror
                </label>

                <label class="mt-5 block">
                    <span class="mb-2 block text-[0.85rem] font-medium">Link to your evidence</span>
                    <input type="url" wire:model="evidence_url" placeholder="https://"
                           class="w-full rounded-control border border-ink/10 bg-paper px-4 py-3 text-[0.92rem] placeholder:text-ink/25 focus:border-brand focus:outline-none dark:border-paper/10 dark:bg-ink dark:placeholder:text-paper/25" />
                    @error('evidence_url') <p class="mt-1.5 text-[0.82rem] text-danger">{{ $message }}</p> @enderror
                    <p class="mt-1.5 text-[0.8rem] text-ink/45 dark:text-paper/45">
                        Where the original is published, a registration record, a contract — whatever shows the right is yours.
                    </p>
                </label>

                {{-- Who is complaining --}}
                <div class="micro mt-10">About you</div>

                <div class="mt-3 grid gap-5 sm:grid-cols-2">
                    <label class="block">
                        <span class="mb-2 block text-[0.85rem] font-medium">Full name *</span>
                        <input type="text" wire:model="claimant_name"
                               class="w-full rounded-control border border-ink/10 bg-paper px-4 py-3 text-[0.92rem] focus:border-brand focus:outline-none dark:border-paper/10 dark:bg-ink" />
                        @error('claimant_name') <p class="mt-1.5 text-[0.82rem] text-danger">{{ $message }}</p> @enderror
                    </label>

                    <label class="block">
                        <span class="mb-2 block text-[0.85rem] font-medium">Email *</span>
                        <input type="email" wire:model="claimant_email"
                               class="w-full rounded-control border border-ink/10 bg-paper px-4 py-3 text-[0.92rem] focus:border-brand focus:outline-none dark:border-paper/10 dark:bg-ink" />
                        @error('claimant_email') <p class="mt-1.5 text-[0.82rem] text-danger">{{ $message }}</p> @enderror
                    </label>

                    <label class="block">
                        <span class="mb-2 block text-[0.85rem] font-medium">Company or organisation</span>
                        <input type="text" wire:model="claimant_organisation"
                               class="w-full rounded-control border border-ink/10 bg-paper px-4 py-3 text-[0.92rem] focus:border-brand focus:outline-none dark:border-paper/10 dark:bg-ink" />
                    </label>

                    <label class="block">
                        <span class="mb-2 block text-[0.85rem] font-medium">You are… *</span>
                        <select wire:model="claimant_role"
                                class="w-full rounded-control border border-ink/10 bg-paper px-4 py-3 text-[0.92rem] focus:border-brand focus:outline-none dark:border-paper/10 dark:bg-ink">
                            <option value="owner">The rights holder</option>
                            <option value="agent">Acting on their behalf</option>
                        </select>
                    </label>

                    <label class="block sm:col-span-2">
                        <span class="mb-2 block text-[0.85rem] font-medium">Postal address</span>
                        <textarea wire:model="claimant_address" rows="2"
                                  class="w-full resize-none rounded-control border border-ink/10 bg-paper px-4 py-3 text-[0.92rem] focus:border-brand focus:outline-none dark:border-paper/10 dark:bg-ink"></textarea>
                        <p class="mt-1.5 text-[0.8rem] text-ink/45 dark:text-paper/45">
                            Needed if the complaint goes further than an email exchange.
                        </p>
                    </label>
                </div>

                {{-- The statement --}}
                <label class="mt-8 flex cursor-pointer items-start gap-3 rounded-control bg-paper p-5 dark:bg-ink">
                    <input type="checkbox" wire:model="sworn"
                           class="mt-0.5 size-4 shrink-0 rounded border-ink/20 text-brand focus:ring-brand dark:border-paper/20" />
                    <span class="text-[0.88rem] leading-relaxed text-ink/70 dark:text-paper/70">
                        I state in good faith that the use described above is not authorised by the rights holder,
                        their agent, or the law, and that the information I have given is accurate. I understand
                        that a knowingly false complaint may make me liable for the damage it causes.
                    </span>
                </label>
                @error('sworn') <p class="mt-1.5 text-[0.82rem] text-danger">{{ $message }}</p> @enderror

                <button wire:click="submit" wire:loading.attr="disabled"
                        class="mt-7 w-full rounded-full bg-brand px-6 py-3.5 font-medium text-white shadow-brand transition duration-300 ease-dbelo hover:-translate-y-0.5 hover:shadow-brand-lg disabled:opacity-50">
                    <span wire:loading.remove wire:target="submit">Send the complaint</span>
                    <span wire:loading wire:target="submit">Sending…</span>
                </button>

                <p class="mt-5 text-[0.84rem] leading-relaxed text-ink/50 dark:text-paper/50">
                    If you are the contributor and want your own sound removed, you do not need this form —
                    ask us from your account and we will take it offline.
                </p>
            </div>
        @endif
    </div>
</div>
