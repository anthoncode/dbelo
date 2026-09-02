<?php

namespace App\Http\Controllers;

use App\Models\Post;
use App\Models\Sound;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Records for the command palette.
 *
 * SCREENS ARE NOT HERE, and that is the design. App\Support\AdminNav
 * ships the whole screen list into the page, so "where is the ad setting"
 * is answered by the browser before the word is finished. This endpoint
 * exists only for the things that live in the database and cannot be
 * shipped up front.
 *
 * Which means: a slow or failed request costs you the records, never the
 * navigation. The palette stays useful on a bad connection, and it stays
 * useful while the database is the thing that is broken — which is a state
 * somebody opens the admin panel specifically to investigate.
 */
class AdminPaletteController extends Controller
{
    /** Per section. Ten of anything is a list, not an answer. */
    private const LIMIT = 5;

    public function __invoke(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);

        $q = trim((string) $request->query('q', ''));

        // Two characters is where a LIKE stops being a search and starts
        // being a table scan that returns everything.
        if (Str::length($q) < 2) {
            return response()->json(['sounds' => [], 'users' => [], 'posts' => []]);
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';

        return response()->json([
            'sounds' => $this->sounds($like),
            'users' => $this->users($like),
            'posts' => $this->posts($like),
        ]);
    }

    private function sounds(string $like): array
    {
        return Sound::query()
            ->where('title', 'like', $like)
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get(['id', 'slug', 'title', 'status'])
            ->map(fn (Sound $s) => [
                'label' => $s->title,
                // The status is the difference between "it is live and
                // wrong" and "it is still waiting" — which is usually the
                // actual reason somebody is looking the sound up.
                'meta' => $s->status,
                'icon' => 'waveform-lines',
                'url' => route('sounds.edit', $s),
            ])
            ->all();
    }

    private function users(string $like): array
    {
        return User::query()
            ->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('email', 'like', $like))
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get(['id', 'name', 'email', 'role'])
            ->map(fn (User $u) => [
                'label' => $u->name,
                'meta' => $u->email,
                'icon' => 'user',
                'url' => route('admin.users.show', $u),
            ])
            ->all();
    }

    private function posts(string $like): array
    {
        return Post::query()
            ->where('title', 'like', $like)
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get(['id', 'title', 'type'])
            ->map(fn (Post $p) => [
                'label' => $p->title,
                'meta' => $p->isPage() ? 'page' : 'post',
                'icon' => $p->isPage() ? 'file' : 'newspaper',
                // Pages and posts share one table and one editor, and the
                // ROUTE NAME is what tells that editor which of the two it
                // is. Sending a page to the blog editor would open it under
                // the wrong list and save it back as the wrong thing.
                'url' => $p->isPage()
                    ? route('admin.pages.edit', $p)
                    : route('admin.blog.edit', $p),
            ])
            ->all();
    }
}
