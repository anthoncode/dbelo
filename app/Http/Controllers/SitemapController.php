<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Collection;
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

            /*
             * The converter, and its per-pair pages.
             *
             * These are static — a whitelist in App\Support\ConverterPairs,
             * not rows in a table — so they never change and never need a
             * lastmod. They earn a high priority because they are the only
             * pages on this site written to rank for something other than
             * the catalogue, and a page Google cannot see cannot do that.
             */
            $urls->push([
                'loc' => route('converter'),
                'changefreq' => 'monthly',
                'priority' => '0.8',
            ]);

            foreach (\App\Support\ConverterPairs::slugs() as $pair) {
                $urls->push([
                    'loc' => route('converter.pair', $pair),
                    'changefreq' => 'monthly',
                    'priority' => '0.7',
                ]);
            }

            if (Post::posts()->live()->exists()) {
                $urls->push([
                    'loc' => route('blog'),
                    'changefreq' => 'weekly',
                    'priority' => '0.7',
                ]);
            }

            /*
             * Packs.
             *
             * Missing until now — the sitemap was written before packs
             * existed and nobody went back. These are the URLs meant to rank
             * for "podcast sound effects" and Google could not see them.
             *
             * Featured only, matching the route: a private collection 404s at
             * /packs/{slug}, so listing one here would advertise a dead URL.
             */
            if (Collection::featured()->exists()) {
                $urls->push([
                    'loc' => route('packs.index'),
                    'changefreq' => 'weekly',
                    'priority' => '0.8',
                ]);

                Collection::featured()
                    ->select(['slug', 'updated_at'])
                    ->get()
                    ->each(function ($pack) use ($urls) {
                        $urls->push([
                            'loc' => route('packs.show', $pack->slug),
                            'lastmod' => $pack->updated_at?->toAtomString(),
                            'changefreq' => 'weekly',
                            'priority' => '0.8',
                        ]);
                    });
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
