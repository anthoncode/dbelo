<?php

use App\Models\Sound;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Search Engine
    |--------------------------------------------------------------------------
    */

    'driver' => env('SCOUT_DRIVER', 'meilisearch'),

    'prefix' => env('SCOUT_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Queue Data Syncing
    |--------------------------------------------------------------------------
    |
    | Indexing happens on the queue, so publishing a sound never waits for
    | Meilisearch to respond. The queue worker is already running for audio
    | processing, so this costs nothing extra.
    |
    */

    'queue' => env('SCOUT_QUEUE', true),

    'after_commit' => false,

    'chunk' => [
        'searchable' => 500,
        'unsearchable' => 500,
    ],

    'soft_delete' => false,

    'identify' => env('SCOUT_IDENTIFY', false),

    /*
    |--------------------------------------------------------------------------
    | Meilisearch
    |--------------------------------------------------------------------------
    */

    'meilisearch' => [
        'host' => env('MEILISEARCH_HOST', 'http://localhost:7700'),
        'key' => env('MEILISEARCH_KEY'),

        /*
        | Index settings are applied with:  php artisan scout:sync-index-settings
        | Run it after any change here.
        */
        'index-settings' => [

            Sound::class => [

                /*
                | Order matters: it is the priority when Meilisearch decides
                | which field a match came from. A hit in the title outranks
                | a hit in the description.
                */
                'searchableAttributes' => [
                    'title',
                    'tags',

                    /*
                    | The translated terms, right after the tags they stand in
                    | for. A Spanish word is as good a signal as the English
                    | one it mirrors, so it outranks a description match for
                    | the same reason "tags" does.
                    |
                    | A FIELD LEFT OUT OF THIS LIST IS NOT SEARCHED AT ALL.
                    | Not ranked low — not searched. That is the failure this
                    | whole feature would die of silently: the column filled,
                    | the index fed, and every Spanish query still empty.
                    */
                    'search_terms',

                    'category',
                    'description',
                ],

                /*
                | Only these can be used in a filter clause. Anything missing
                | here throws an error at query time rather than silently
                | returning everything.
                */
                'filterableAttributes' => [
                    'type',
                    'category_slug',
                    'parent_category_slug',
                    'license_slug',
                    'is_premium',
                    'is_loopable',
                    'duration_ms',

                    /*
                    | The music side. Null on every sound effect, which the
                    | engine handles natively — a filter on genre simply
                    | matches no effects, which is the whole point.
                    |
                    | bpm and musical_key are here before any screen filters
                    | by them, on purpose: adding an attribute to this list
                    | means re-applying the index settings, and doing that
                    | once now is cheaper than remembering to do it later.
                    */
                    'genre',
                    'mood',
                    'bpm',
                    'musical_key',
                    'has_vocals',
                ],

                'sortableAttributes' => [
                    'published_at',
                    'downloads_count',
                    'duration_ms',
                    'title',

                    // "Slowest first" on the music page, eventually. Same
                    // argument as the two filterable fields above: declaring
                    // it costs a line, forgetting it costs a failed search
                    // that looks like an empty catalogue.
                    'bpm',
                ],

                /*
                | Default relevance order. 'exactness' before 'proximity'
                | favours the sound literally called "Thunder" over one whose
                | description happens to mention thunder twice.
                */
                'rankingRules' => [
                    'words',
                    'typo',
                    'exactness',
                    'proximity',
                    'attribute',
                    'sort',
                    'downloads_count:desc',
                ],

                /*
                | Typo tolerance is the whole point: "chiriando" must still
                | find "chirriando". One typo from 4 characters, two from 8.
                */
                'typoTolerance' => [
                    'enabled' => true,
                    'minWordSizeForTypos' => [
                        'oneTypo' => 4,
                        'twoTypos' => 8,
                    ],
                ],

                /*
                | People do not search with your vocabulary. They search with
                | theirs. Every synonym here is a search that would otherwise
                | have returned nothing.
                */
                'synonyms' => [
                    'car' => ['automobile', 'vehicle', 'auto'],
                    'automobile' => ['car', 'vehicle'],
                    'gun' => ['firearm', 'pistol', 'rifle', 'weapon'],
                    'gunshot' => ['gunfire', 'shot', 'bang'],
                    'explosion' => ['blast', 'boom', 'detonation'],
                    'footsteps' => ['footstep', 'walking', 'steps', 'walk'],
                    'thunder' => ['thunderstorm', 'storm', 'lightning'],
                    'rain' => ['rainfall', 'raining', 'downpour'],
                    'door' => ['doorway', 'gate'],
                    'creak' => ['creaking', 'squeak', 'squeaky'],
                    'whoosh' => ['swoosh', 'swish', 'woosh'],
                    'beep' => ['bleep', 'chirp', 'blip'],
                    'click' => ['clicking', 'tap', 'tick'],
                    'crowd' => ['audience', 'people', 'chatter'],
                    'water' => ['liquid', 'splash'],
                    'fire' => ['flame', 'burning', 'crackle'],
                    'wind' => ['breeze', 'gust'],
                    'laser' => ['blaster', 'zap', 'phaser'],
                    'robot' => ['android', 'droid', 'mechanical'],
                    'spaceship' => ['starship', 'spacecraft', 'ufo'],
                    'punch' => ['hit', 'impact', 'smack'],
                    'scream' => ['screaming', 'shout', 'yell'],
                    'ui' => ['interface', 'menu', 'button'],
                    'notification' => ['alert', 'ping', 'notify'],
                    'ambience' => ['ambient', 'atmosphere', 'background'],
                ],

                /*
                | Words too common to be worth matching on: they would drag
                | every result in the index into every search.
                */
                'stopWords' => [
                    'a', 'an', 'the', 'of', 'in', 'on', 'at', 'to', 'and', 'or',
                    'sound', 'sfx', 'effect', 'audio', 'fx',
                ],

                'pagination' => [
                    'maxTotalHits' => 5000,
                ],
            ],
        ],
    ],
];
