<?php

namespace App\Services\AI;

use App\Support\Suggestions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Google's Gemini, over plain HTTP.
 *
 * No SDK, same reasoning as PayPalClient: this is one endpoint, and a
 * dependency whose release cadence is not ours is a dependency that breaks
 * on somebody else's schedule.
 *
 * ── THE FREE TIER IS THE DESIGN TARGET ───────────────────────────────────
 *
 * dbelo uploads about fifty sounds a day, which is nothing against any free
 * quota. The one consequence that matters is that 429 is a NORMAL answer
 * during a large backfill, not a fault — so it is raised as a retryable
 * AiException and the job simply comes back later. Treating it as a failure
 * would fill the failed_jobs table with work that was never broken.
 *
 * Also worth stating plainly, because it is a real trade and not a footnote:
 * on the free tier Google may use what is sent here to improve their
 * products. Everything dbelo sends is a filename and a duration for a sound
 * that will be public anyway. Nothing private goes through this class.
 */
class GeminiProvider implements AiProvider
{
    private const BASE = 'https://generativelanguage.googleapis.com/v1beta/models';

    public function name(): string
    {
        return 'gemini';
    }

    /*
     * Both of these go through App\Support\Suggestions rather than config()
     * directly, so that what the panel says is in effect and what actually
     * gets sent are the same value. A driver reading config() while a screen
     * reads the settings table is two answers to one question.
     */

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

        $url = self::BASE.'/'.$this->model().':generateContent';

        try {
            $response = Http::withHeaders(['x-goog-api-key' => $this->key()])
                ->timeout(Suggestions::timeout())
                ->connectTimeout(10)
                ->post($url, [
                    // The instructions are sent as a system instruction rather
                    // than glued onto the front of the user text, so the
                    // constraints are not competing for attention with the
                    // facts about the sound.
                    'systemInstruction' => ['parts' => [['text' => $system]]],
                    'contents' => [['role' => 'user', 'parts' => [['text' => $user]]]],
                    'generationConfig' => [
                        'responseMimeType' => 'application/json',
                        'responseSchema' => $this->toGeminiSchema($schema),
                        // Low, not zero. Zero makes every description in the
                        // catalogue come out of the same mould, and two
                        // thousand identical-sounding meta descriptions are
                        // what search engines throw away.
                        'temperature' => 0.4,
                    ],
                ]);
        } catch (ConnectionException $e) {
            throw new AiException('Gemini could not be reached: '.$e->getMessage(), $this->name(), 0, true);
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

        $text = $response->json('candidates.0.content.parts.0.text');

        if (! is_string($text) || trim($text) === '') {
            // A blocked or empty candidate. Rare, and never worth guessing at.
            throw AiException::unreadable($this->name(), 'empty candidate');
        }

        $decoded = json_decode($text, true);

        if (! is_array($decoded)) {
            throw AiException::unreadable($this->name(), 'the answer was not JSON');
        }

        return $decoded;
    }

    /**
     * JSON Schema → the subset Gemini accepts.
     *
     * Gemini's responseSchema is close to JSON Schema but not identical: it
     * rejects `additionalProperties`, and it wants types upper-cased. Rather
     * than keeping two schemas in the codebase — which would drift the first
     * time one is edited — the canonical one is written once in
     * SoundSuggester and translated here.
     */
    private function toGeminiSchema(array $schema): array
    {
        $out = [];

        foreach ($schema as $key => $value) {
            if ($key === 'additionalProperties') {
                continue;
            }

            if ($key === 'type' && is_string($value)) {
                $out['type'] = strtoupper($value);

                continue;
            }

            $out[$key] = is_array($value) ? $this->toGeminiSchema($value) : $value;
        }

        return $out;
    }
}
