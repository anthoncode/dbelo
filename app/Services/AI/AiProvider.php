<?php

namespace App\Services\AI;

/**
 * One model provider, reduced to the only thing dbelo asks of it.
 *
 * ── WHY AN INTERFACE FOR SOMETHING WITH TWO IMPLEMENTATIONS ──────────────
 *
 * Not to be clever. Because the honest way to choose between Gemini and
 * OpenAI for THIS catalogue is to run both over the same twenty filenames
 * and read the tags side by side — and that comparison is only cheap if
 * swapping is a value in config rather than an afternoon.
 *
 * It also means the day one of them changes its endpoint, raises a price,
 * or is simply having a bad week, the fix is one line of .env instead of a
 * rewrite of whatever calls it.
 *
 * ── WHY THE METHOD TAKES A SCHEMA ────────────────────────────────────────
 *
 * The single most important property of this integration is that the answer
 * comes back as JSON with known keys, every time. Both providers support
 * exactly that — responseSchema on Gemini, json_schema on OpenAI — and both
 * drivers are required to use it rather than asking nicely in the prompt and
 * hoping. A model that returns a friendly paragraph breaks the job silently,
 * which is the one failure mode worth engineering against.
 */
interface AiProvider
{
    /**
     * Send one prompt, get structured data back.
     *
     * @param  string  $system  How to behave. Constraints live here.
     * @param  string  $user  The facts about this one sound.
     * @param  array  $schema  JSON Schema the answer must satisfy.
     * @return array  The decoded answer, already matching the schema.
     *
     * @throws AiException
     */
    public function complete(string $system, string $user, array $schema): array;

    /** Both halves of the credentials present. Says nothing about whether they work. */
    public function isConfigured(): bool;

    /** For the log line and the `ai_provider` column: 'gemini', 'openai'. */
    public function name(): string;

    /** The exact model that answered, recorded so a change in quality is traceable. */
    public function model(): string;
}
