<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Lets an admin see the site exactly as a given user sees it — the fastest
 * way to resolve "the download button does nothing for me".
 *
 * Two rules make it safe: the original admin id is kept in the session so
 * the trip is always reversible, and both directions are written to the
 * audit log. An impersonation nobody can see is a backdoor.
 */
class ImpersonationController extends Controller
{
    public const SESSION_KEY = 'impersonator_id';

    public function start(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);

        // No impersonating another admin: that would let one admin act as
        // another with the trail pointing at the wrong person.
        abort_if($user->isAdmin(), 403, 'You cannot impersonate another admin.');
        abort_if($user->id === $request->user()->id, 403);

        ActivityLog::record(
            'user.impersonation.started',
            $user,
            "{$request->user()->name} started impersonating {$user->name}",
        );

        $request->session()->put(self::SESSION_KEY, $request->user()->id);

        auth()->login($user);

        return redirect()->route('home');
    }

    public function stop(Request $request): RedirectResponse
    {
        $adminId = $request->session()->pull(self::SESSION_KEY);

        abort_unless($adminId, 403);

        $impersonated = $request->user();
        $admin = User::findOrFail($adminId);

        auth()->login($admin);

        ActivityLog::record(
            'user.impersonation.stopped',
            $impersonated,
            "{$admin->name} stopped impersonating {$impersonated?->name}",
        );

        return redirect()->route('admin.users');
    }
}
