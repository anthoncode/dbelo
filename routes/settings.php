<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The account area
|--------------------------------------------------------------------------
|
| ── WHY `verified` IS NOT ON ANY OF THESE ────────────────────────────────
|
| It used to be, on appearance and security, and it did nothing — because
| Laravel's EnsureEmailIsVerified checks `$user instanceof MustVerifyEmail`
| and the User model did not implement it. The moment that interface was
| added, this file would have started locking unconfirmed accounts out of
| their own security settings: 2FA, passkeys, password. The screens that
| protect an account are the last ones to hide behind a confirmation.
|
| It was also the wrong mechanism. This project decided that verification is
| a SETTING — Admin → Settings → Security — enforced by
| App\Http\Middleware\RequireVerifiedEmail on downloading and uploading, and
| nowhere else. Laravel's middleware is on or off in the route file and
| cannot read that setting, which is exactly what RequireVerifiedEmail's own
| docblock says it was written to avoid. Two policies, one of them invisible.
|
*/

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::livewire('settings/profile', 'pages::settings.profile')->name('profile.edit');

    /*
    | What this account is: plan, renewal date, downloads used today, and
    | what has been paid. Every figure on it already existed in the database
    | and in the admin panel; the person it belongs to had nowhere to see it.
    |
    | No `verified` and no plan check: an account with no plan is exactly the
    | account most likely to open this page.
    */
    Route::livewire('settings/plan', 'pages::settings.plan')->name('plan.show');

    Route::livewire('settings/appearance', 'pages::settings.appearance')->name('appearance.edit');

    /*
    | password.confirm stays. It is the right gate here and a different one
    | from verification: it asks "are you still the person who signed in",
    | which is the question that matters on a screen holding 2FA and
    | passkeys.
    */
    Route::livewire('settings/security', 'pages::settings.security')
        ->middleware([
            'password.confirm',
        ])
        ->name('security.edit');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
