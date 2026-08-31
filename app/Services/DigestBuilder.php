<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\Collection;
use App\Models\Post;
use App\Models\Sound;
use Carbon\CarbonInterface;

/**
 * Writes the weekly email so nobody has to.
 *
 * This is the whole reason the digest exists rather than a campaign you sit
 * down to compose: a newsletter that needs writing gets sent for three weeks
 * and then never again. One that assembles itself from the catalogue is
 * still going out next year.
 */
class DigestBuilder
{
    public function __construct(protected int $days = 7) {}

    /**
     * CarbonInterface, not Carbon: AppServiceProvider sets the project to
     * CarbonImmutable, so every date in dbelo is an immutable instance and a
     * concrete type hint here would reject the very thing now() returns.
     */
    public function since(): CarbonInterface
    {
        return now()->subDays($this->days);
    }

    /**
     * @return array{sounds: \Illuminate\Support\Collection, packs: \Illuminate\Support\Collection, post: ?Post, total: int}
     */
    public function build(Campaign $digest): array
    {
        $blocks = $digest->blocks ?? ['sounds', 'packs', 'post'];

        $sounds = in_array('sounds', $blocks, true)
            ? Sound::published()
                ->with(['category', 'files'])
                ->where('published_at', '>=', $this->since())
                ->orderByDesc('is_featured')
                ->orderByDesc('published_at')
                ->limit(8)
                ->get()
            : collect();

        $packs = in_array('packs', $blocks, true)
            ? Collection::query()
                ->where('is_featured', true)
                ->where('updated_at', '>=', $this->since())
                ->orderByDesc('updated_at')
                ->limit(2)
                ->get()
            : collect();

        $post = in_array('post', $blocks, true)
            ? Post::posts()->live()->with('cover')->latest('published_at')->first()
            : null;

        return [
            'sounds' => $sounds,
            'packs' => $packs,
            'post' => $post,
            'total' => $sounds->count() + $packs->count() + ($post ? 1 : 0),
        ];
    }

    /**
     * A quiet week is not a reason to email anyone.
     *
     * "Here is nothing new" trains people to stop opening, and once they stop
     * opening they never start again. Skipping a week costs nothing.
     */
    public function worthSending(Campaign $digest): bool
    {
        $content = $this->build($digest);

        return $content['sounds']->count() >= 3 || $content['total'] >= 4;
    }

    /** The subject line, written from what actually went in. */
    public function subject(Campaign $digest): string
    {
        $content = $this->build($digest);
        $count = $content['sounds']->count();

        if ($count === 0) {
            return $digest->subject;
        }

        return $count === 1
            ? 'One new sound this week'
            : "{$count} new sounds this week";
    }
}
