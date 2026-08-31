<?php

namespace App\Http\Controllers;

use App\Models\Post;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * RSS for the blog.
 *
 * Still the cheapest distribution there is: readers subscribe once and every
 * post reaches them without an algorithm in the middle. Twenty lines, and it
 * is the only channel nobody can take away from you.
 */
class FeedController extends Controller
{
    public function __invoke(): Response
    {
        $xml = Cache::remember('blog.feed', now()->addHour(), function () {
            $posts = Post::posts()
                ->live()
                ->with('author:id,name')
                ->latest('published_at')
                ->limit(20)
                ->get();

            $items = $posts->map(fn (Post $post) => [
                'title' => $post->title,
                'link' => $post->url(),
                'description' => $post->summary(300),
                'pubDate' => $post->published_at->toRfc2822String(),
                'author' => $post->author?->name,
            ]);

            return view('feed', [
                'items' => $items,
                'updated' => $posts->first()?->published_at?->toRfc2822String() ?? now()->toRfc2822String(),
            ])->render();
        });

        return response($xml, 200, ['Content-Type' => 'application/rss+xml; charset=UTF-8']);
    }
}
