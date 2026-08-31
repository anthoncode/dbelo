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
                ],

                'sortableAttributes' => [
                    'published_at',
                    'downloads_count',
                    'duration_ms',
                    'title',
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
