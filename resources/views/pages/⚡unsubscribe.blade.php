<?php

use App\Models\Campaign;
use App\Models\Subscriber;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.site')] #[Title('Email preferences')] class extends Component {
    public Subscriber $subscriber;

    public bool $done = false;

    public function mount(string $token): void
    {
        $this->subscriber = Subscriber::where('token', $token)->firstOrFail();

        // The one-click POST from Gmail is handled by its own controller:
        // it arrives without a session and expects an empty 200, not a page.
        $this->done = ! $this->subscriber->isSubscribed();

        view()->share('seo', ['noindex' => true, 'title' => 'Email preferences']);
    }

    public function unsubscribeAll(): void
    {
        $this->subscriber->unsubscribe();
        $this->subscriber->refresh();
        $this->done = true;
    }

    public function keepOnly(string $type): void
    {
        // Turning off the other half rather than everything: someone who only
        // wanted the offers gone should not have to lose the sounds too.
        $this->subscriber->unsubscribe(
            $type === 'digest' ? Campaign::TYPE_PROMO : Campaign::TYPE_DIGEST
        );

        $this->subscriber->refresh();
        $this->done = true;
    }

    public function resubscribe(): void
    {
        $this->subscriber->resubscribe();
        $this->subscriber->refresh();
        $this->done = false;
    }
}; ?>

<div>
    <div class="mx-auto max-w-xl py-12">
        <div class="rounded-card bg-surface p-9 shadow-soft-md dark:bg-surface-dark">

            @if ($done && ! $subscriber->isSubscribed())
                <div class="text-center">
                    <span class="mx-auto grid size-14 place-items-center rounded-full bg-ink/[0.05] text-ink/40 dark:bg-paper/10 dark:text-paper/40">
                        <x-icon name="envelope-open" style="solid" class="text-lg" />
                    </span>

                    <h1 class="mt-5 text-2xl font-semibold">You are unsubscribed</h1>
                    <p class="mt-3 leading-relaxed text-ink/60 dark:text-paper/60">
                        We will not email <strong class="font-medium">{{ $subscriber->email }}</strong> again about
                        new sounds or offers. Account emails — password resets, download receipts — still work,
                        because those are not marketing.
                    </p>

                    <div class="mt-7 flex flex-wrap justify-center gap-3">
                        <a href="{{ route('sounds.index') }}" wire:navigate
                           class="rounded-full bg-brand px-6 py-3 font-medium text-white shadow-brand transition duration-300 ease-dbelo hover:-translate-y-0.5">
                            Back to the catalogue
                        </a>
                        <button wire:click="resubscribe"
                                class="rounded-full bg-ink/[0.05] px-6 py-3 text-ink/60 transition duration-300 ease-dbelo hover:text-ink dark:bg-paper/10 dark:text-paper/60 dark:hover:text-paper">
                            Undo
                        </button>
                    </div>
                </div>

            @elseif ($done)
                <div class="text-center">
                    <span class="mx-auto grid size-14 place-items-center rounded-full bg-brand/15 text-brand">
                        <x-icon name="circle-check" style="solid" class="text-lg" />
                    </span>

                    <h1 class="mt-5 text-2xl font-semibold">Preferences saved</h1>
                    <p class="mt-3 leading-relaxed text-ink/60 dark:text-paper/60">
                        {{ $subscriber->wants_digest && ! $subscriber->wants_promos
                            ? 'You will keep getting the weekly sounds, and nothing about offers.'
                            : 'You will only hear from us about offers.' }}
                    </p>

                    <a href="{{ route('sounds.index') }}" wire:navigate
                       class="mt-7 inline-flex rounded-full bg-brand px-6 py-3 font-medium text-white shadow-brand transition duration-300 ease-dbelo hover:-translate-y-0.5">
                        Back to the catalogue
                    </a>
                </div>

            @else
                <div class="micro">Email preferences</div>
                <h1 class="mt-2 text-2xl font-semibold">Before you go</h1>
                <p class="mt-3 leading-relaxed text-ink/60 dark:text-paper/60">
                    We send two very different things to <strong class="font-medium">{{ $subscriber->email }}</strong>.
                    You can drop one and keep the other.
                </p>

                <div class="mt-7 space-y-3">
                    <button wire:click="keepOnly('digest')"
                            class="flex w-full items-start gap-4 rounded-card bg-paper p-5 text-left transition duration-300 ease-dbelo hover:-translate-y-0.5 hover:shadow-soft-md dark:bg-ink">
                        <x-icon name="waveform-lines" style="solid" class="mt-0.5 text-brand" />
                        <span>
                            <span class="block font-medium">Only the weekly sounds</span>
                            <span class="mt-1 block text-[0.88rem] leading-relaxed text-ink/55 dark:text-paper/55">
                                What was added to the catalogue. No offers, no promotions.
                            </span>
                        </span>
                    </button>

                    <button wire:click="keepOnly('promos')"
                            class="flex w-full items-start gap-4 rounded-card bg-paper p-5 text-left transition duration-300 ease-dbelo hover:-translate-y-0.5 hover:shadow-soft-md dark:bg-ink">
                        <x-icon name="tag" style="solid" class="mt-0.5 text-action" />
                        <span>
                            <span class="block font-medium">Only offers</span>
                            <span class="mt-1 block text-[0.88rem] leading-relaxed text-ink/55 dark:text-paper/55">
                                A few times a year, when there is something worth telling you.
                            </span>
                        </span>
                    </button>

                    <button wire:click="unsubscribeAll"
                            class="flex w-full items-start gap-4 rounded-card border border-ink/10 p-5 text-left transition duration-300 ease-dbelo hover:border-ink/20 dark:border-paper/10 dark:hover:border-paper/20">
                        <x-icon name="ban" style="solid" class="mt-0.5 text-ink/35 dark:text-paper/35" />
                        <span>
                            <span class="block font-medium">Nothing at all</span>
                            <span class="mt-1 block text-[0.88rem] leading-relaxed text-ink/55 dark:text-paper/55">
                                No marketing email of any kind. Your account keeps working.
                            </span>
                        </span>
                    </button>
                </div>
            @endif
        </div>
    </div>
</div>
