<?php

use App\Concerns\ProfileValidationRules;
use Flux\Flux;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.site')] #[Title('Profile settings')] class extends Component
{
    use ProfileValidationRules;

    public string $name = '';

    public string $email = '';

    public function mount(): void
    {
        $this->name = Auth::user()->name;
        $this->email = Auth::user()->email;
    }

    public function updateProfileInformation(): void
    {
        $user = Auth::user();

        $validated = $this->validate($this->profileRules($user->id));

        $user->fill($validated);

        /*
         * Changing the address un-verifies it.
         *
         * Without this, typing somebody else's address into the field would
         * hand you an account that claims to be a verified owner of it.
         */
        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        Flux::toast(variant: 'success', text: __('Profile updated.'));
    }

    public function resendVerificationNotification(): void
    {
        $user = Auth::user();

        if ($user->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('library', absolute: false));

            return;
        }

        $user->sendEmailVerificationNotification();

        Session::flash('status', 'verification-link-sent');
    }

    #[Computed]
    public function hasUnverifiedEmail(): bool
    {
        return Auth::user() instanceof MustVerifyEmail && ! Auth::user()->hasVerifiedEmail();
    }

    #[Computed]
    public function showDeleteUser(): bool
    {
        return ! Auth::user() instanceof MustVerifyEmail
            || (Auth::user() instanceof MustVerifyEmail && Auth::user()->hasVerifiedEmail());
    }
}; ?>

<x-pages::settings.layout
    :heading="__('Profile')"
    :subheading="__('The name shown next to anything you upload, and the address we use to reach you.')">

    <form wire:submit="updateProfileInformation" class="space-y-6">

        {{-- The avatar is shown, not edited, on purpose: there is no upload
             for it yet. Showing it here anyway is what makes the page feel
             like an account rather than two text boxes — and it is the same
             component the header uses, so it can never fall out of step. --}}
        <div class="flex items-center gap-4">
            <x-user-avatar class="size-14 text-[1.05rem]" />

            <div class="min-w-0">
                <div class="text-[0.95rem]">{{ auth()->user()->name }}</div>
                <div class="micro mt-0.5">{{ __('Member since') }} {{ auth()->user()->created_at?->format('F Y') }}</div>
            </div>
        </div>

        <flux:input wire:model="name" :label="__('Name')" type="text" required autofocus autocomplete="name" />

        <div>
            <flux:input wire:model="email" :label="__('Email')" type="email" required autocomplete="email" />

            @if ($this->hasUnverifiedEmail)
                {{-- A tinted block rather than a line of text. An unverified
                     address silently blocks downloads and password resets,
                     and a sentence in the same grey as the hint under every
                     other field is a sentence nobody reads. --}}
                <div class="mt-3 flex items-start gap-2.5 rounded-control bg-warning/10 px-4 py-3">
                    <x-icon name="envelope-circle-check" style="solid" class="mt-[0.15rem] shrink-0 text-[0.8rem] text-warning" />

                    <div class="min-w-0 text-[0.85rem] leading-relaxed">
                        <span class="text-ink/75 dark:text-paper/75">{{ __('This address has not been confirmed yet.') }}</span>

                        <button type="button" wire:click.prevent="resendVerificationNotification"
                                class="ml-1 text-brand underline underline-offset-2 hover:opacity-80">
                            {{ __('Send the link again') }}
                        </button>

                        @if (session('status') === 'verification-link-sent')
                            <div class="mt-1.5 text-success">{{ __('Sent. Check your inbox.') }}</div>
                        @endif
                    </div>
                </div>
            @endif
        </div>

        <div class="flex items-center gap-4">
            <flux:button variant="primary" type="submit" data-test="update-profile-button">
                {{ __('Save') }}
            </flux:button>
        </div>
    </form>

    @if ($this->showDeleteUser)
        <livewire:pages::settings.delete-user-form />
    @endif
</x-pages::settings.layout>
