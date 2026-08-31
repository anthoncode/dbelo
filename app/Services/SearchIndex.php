<?php

namespace App\Services;

use App\Models\Sound;
use App\Models\Synonym;
use Meilisearch\Client;
use Throwable;

/**
 * The bridge to Meilisearch: health, and pushing synonyms.
 *
 * Health matters more than it looks. When Meilisearch dies the catalogue
 * stops responding and nothing on the site says so — it is one of the two
 * failures that break dbelo silently. Any screen that reports on search has
 * to say out loud whether the engine is even answering.
 */
class SearchIndex
{
    public function client(): Client
    {
        return new Client(
            config('scout.meilisearch.host'),
            config('scout.meilisearch.key'),
        );
    }

    public function indexName(): string
    {
        return (new Sound)->searchableAs();
    }

    /**
     * @return array{up: bool, documents: int, indexing: bool, error: ?string}
     */
    public function health(): array
    {
        try {
            $stats = $this->client()->index($this->indexName())->stats();

            return [
                'up' => true,
                'documents' => (int) ($stats['numberOfDocuments'] ?? 0),
                'indexing' => (bool) ($stats['isIndexing'] ?? false),
                'error' => null,
            ];
        } catch (Throwable $e) {
            return [
                'up' => false,
                'documents' => 0,
                'indexing' => false,
                'error' => $this->explain($e),
            ];
        }
    }

    /** How many published sounds SHOULD be in the index. */
    public function expected(): int
    {
        return Sound::published()->count();
    }

    /**
     * Built-in groups from config/scout.php merged with the ones added from
     * the panel. The database wins on conflicts, so a group can be adjusted
     * without touching the file it came from.
     *
     * @return array<string, array<int, string>>
     */
    public function synonyms(): array
    {
        return array_merge($this->builtIn(), $this->custom());
    }

    /** @return array<string, array<int, string>> */
    public function builtIn(): array
    {
        $settings = config('scout.meilisearch.index-settings.'.Sound::class, []);

        return $settings['synonyms'] ?? [];
    }

    /** @return array<string, array<int, string>> */
    public function custom(): array
    {
        return Synonym::pluck('replacements', 'term')
            ->map(fn ($words) => (array) $words)
            ->all();
    }

    /**
     * Send the merged map to the engine.
     *
     * Returns the error string rather than throwing: Meilisearch runs as a
     * separate process and being down is a normal Tuesday in development.
     * The screen should say so, not blow up.
     */
    public function push(): ?string
    {
        try {
            $this->client()
                ->index($this->indexName())
                ->updateSettings(['synonyms' => $this->synonyms()]);

            return null;
        } catch (Throwable $e) {
            return $this->explain($e);
        }
    }

    /**
     * Turn a library exception into something worth reading.
     *
     * "cURL error 7: Failed to connect to 127.0.0.1 port 7700" is accurate
     * and useless: it says what the HTTP client did, not what is wrong or
     * what to do. Every one of these has a plain-language cause.
     */
    protected function explain(Throwable $e): string
    {
        $host = config('scout.meilisearch.host', 'http://127.0.0.1:7700');
        $message = $e->getMessage();

        if (str_contains($message, 'Failed to connect')
            || str_contains($message, 'cURL error 7')
            || str_contains($message, 'Connection refused')) {
            return "Meilisearch is not running at {$host}.";
        }

        if (str_contains($message, 'cURL error 28') || str_contains($message, 'timed out')) {
            return "Meilisearch is at {$host} but did not answer in time. It may still be starting up.";
        }

        if (str_contains($message, 'invalid_api_key') || str_contains($message, 'missing_authorization_header')) {
            return 'Meilisearch rejected the key. MEILISEARCH_KEY in .env does not match the master key it was started with.';
        }

        if (str_contains($message, 'index_not_found')) {
            return 'The index does not exist yet. Run: php artisan scout:import "App\\Models\\Sound"';
        }

        return $message;
    }
}
