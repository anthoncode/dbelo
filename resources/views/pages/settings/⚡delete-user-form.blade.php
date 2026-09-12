<?php

use Livewire\Component;

new class extends Component {}; ?>

{{--
    Deleting the account.

    Separated from the fields above by a real divider and wrapped in danger,
    because it sits at the bottom of the same card as "save your name" and
    the two are not the same kind of button. The distance and the colour are
    the only things stopping a mis-click, since the confirmation modal is one
    step away.

    The modal itself is left as Flux: it is opened rarely, it works, and the
    consequences of a half-rewritten confirmation dialog are permanent.
--}}
<section class="mt-8 border-t border-ink/10 pt-7 dark:border-paper/10">
    <div class="flex items-start gap-3.5">
        <span class="grid size-9 shrink-0 place-items-center rounded-full bg-danger/12 text-danger">
            <x-icon name="trash-can" style="solid" class="text-[0.82rem]" />
        </span>

        <div class="min-w-0 flex-1">
            <h3 class="text-[0.98rem] font-medium">{{ __('Delete account') }}</h3>

            <p class="mt-1 max-w-[58ch] text-[0.85rem] leading-relaxed text-ink/55 dark:text-paper/55">
                {{ __('Your account, your favourites and your download history are removed for good. Sounds you have already downloaded stay yours to use under the licence they came with.') }}
            </p>

            <div class="mt-4">
                <flux:modal.trigger name="confirm-user-deletion">
                    <flux:button variant="danger" data-test="delete-user-button">
                        {{ __('Delete account') }}
                    </flux:button>
                </flux:modal.trigger>
            </div>
        </div>
    </div>

    <livewire:pages::settings.delete-user-modal />
</section>
