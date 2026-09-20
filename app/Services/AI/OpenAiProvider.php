<?php

namespace App\Services\AI;

use App\Support\Suggestions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * OpenAI, over plain HTTP.
 *
 * Written at the same time as the Gemini driver and not "later, if needed",
 * because the only honest way to choose between them for this catalogue is
 * to run both over the same twenty real filenames and read the tags side by
 * side. A driver that does not exist cannot be compared.
 *
 * ── ITS FREE PATH IS NOT THE SAME AS GOOGLE'S ────────────────────────────
 *
 * OpenAI stopped handing out signup credits. What is free now is an opt-in
 * data-sharing programme — generous daily tokens on the mini models in
 * exchange for prompts and answers being used for training — and it still
 * requires a card on file. Worth knowing before assuming the two providers
 * are interchangeable on terms as well as on price.
 */
class OpenAiProvider implements AiProvider
{
    private const URL = 'https://api.openai.com/v1/chat/completions';

    public function name(): string
    {
        return 'openai';
    }

    /* Through Suggestions, not config(). See the note in GeminiProvider. */

    public function model(): string
    {
        return Suggestions::model($this->name());
    }

    private function key(): ?string
    {
        return Suggestions::key($this->name());
    }

    public function isConfigured(): bool
    {
        return $this->key() !== null;
    }

    public function complete(string $system, string $user, array $schema): array
    {
        if (! $this->isConfigured()) {
            throw AiException::notConfigured($this->name());
        }

        try {
            $response = Http::withToken($this->key())
                ->timeout(Suggestions::timeout())
                ->connectTimeout(10)
                ->post(self::URL, [
                    'model' => $this->model(),
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $user],
                    ],
                    /*
                     * strict: true is the whole point. Without it this is a
                     * request rather than a guarantee, and the one failure
                     * worth engineering against here is a model that answers
                     * with a helpful paragraph instead of the object.
                     */
                    'response_format' => [
                        'type' => 'json_schema',
                        'json_schema' => [
                            'name' => 'sound_metadata',
                            'strict' => true,
                            'schema' => $schema,
                        ],
                    ],
                    'temperature' => 0.4,
                ]);
        } catch (ConnectionException $e) {
            throw new AiException('OpenAI could not be reached: '.$e->getMessage(), $this->name(), 0, true);
        }

        if ($response->status() === 429 || $response->status() === 503) {
            throw AiException::throttled($this->name(), $response->status());
        }

        if ($response->failed()) {
            throw AiException::refused(
                $this->name(),
                $response->status(),
                (string) ($response->json('error.message') ?? 'no message'),
            );
        }

        $text = $response->json('choices.0.message.content');

        if (! is_string($text) || trim($text) === '') {
            throw AiException::unreadable($this->name(), 'empty message');
        }

        $decoded = json_decode($text, true);

        if (! is_array($decoded)) {
            throw AiException::unreadable($this->name(), 'the answer was not JSON');
        }

        return $decoded;
    }
}
