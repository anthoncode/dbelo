<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Post;
use App\Models\Sound;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * XML sitemap. Every published sound is a page that can rank on its own,
 * so this is how Google finds them without crawling the whole catalogue
 * link by link.
 */
class SitemapController extends Controller
{
    public function __invoke(): Response
    {
        // Regenerating this on every crawler hit would scan the whole
        // catalogue. An hour of cache is invisible to Google and free for us.
        $xml = Cache::remember('sitemap.xml', now()->addHour(), function () {
            $urls = collect();

            $urls->push([
                'loc' => route('home'),
                'changefreq' => 'daily',
                'priority' => '1.0',
            ]);

            $urls->push([
                'loc' => route('sounds.index'),
                'changefreq' => 'daily',
                'priority' => '0.9',
            ]);

            Category::orderBy('id')->get()->each(function ($category) use ($urls) {
                $urls->push([
                    'loc' => route('sounds.index', ['category' => $category->slug]),
                    'changefreq' => 'weekly',
                    'priority' => '0.7',
                ]);
            });

            // Pages and posts. Anything flagged noindex is left out — telling
            // Google "here it is" and "do not index it" at once is a mixed
            // signal that helps nobody.
            Post::live()
                ->where('noindex', false)
                ->select(['type', 'slug', 'updated_at'])
                ->get()
                ->each(function (Post $post) use ($urls) {
                    $urls->push([
                        'loc' => $post->url(),
                        'lastmod' => $post->updated_at?->toAtomString(),
                        'changefreq' => $post->isPage() ? 'monthly' : 'weekly',
                        'priority' => $post->isPage() ? '0.5' : '0.6',
                    ]);
                });

            if (Post::posts()->live()->exists()) {
                $urls->push([
                    'loc' => route('blog'),
                    'changefreq' => 'weekly',
                    'priority' => '0.7',
                ]);
            }

            Sound::published()
                ->select(['slug', 'updated_at'])
                ->orderByDesc('published_at')
                ->chunk(1000, function ($sounds) use ($urls) {
                    foreach ($sounds as $sound) {
                        $urls->push([
                            'loc' => route('sounds.show', $sound->slug),
                            'lastmod' => $sound->updated_at?->toAtomString(),
                            'changefreq' => 'monthly',
                            'priority' => '0.8',
                        ]);
                    }
                });

            return view('sitemap', ['urls' => $urls])->render();
        });

        return response($xml, 200, ['Content-Type' => 'application/xml']);
    }
}
