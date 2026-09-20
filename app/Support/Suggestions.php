<?php

namespace App\Support;

use App\Models\Setting;
use App\Support\Concerns\SettingFields;

/**
 * The settings behind the metadata suggester.
 *
 * ── WHY THESE KEYS LIVE IN THE PANEL AND THE PAYPAL ONES DO NOT ──────────
 *
 * That inconsistency is deliberate, and it comes down to two things.
 *
 * Blast radius. A leaked PayPal secret is somebody creating subscriptions,
 * issuing refunds and reading the merchant account. A leaked model key is
 * somebody spending a quota — no data, no money movement, nothing done in
 * dbelo's name — and it is revoked from the provider's console in seconds.
 *
 * How often it is touched. Payment credentials change about three times in
 * the life of a site. The provider and the model get changed while the two
 * drivers are being compared over real sounds, and asking somebody to edit
 * a file and clear a cache for each try is exactly the friction that means
 * the comparison never happens.
 *
 * The backup dump still carries whatever is stored here. That is accepted
 * for this one key, on the reasoning above — and it is why the screen says
 * to set a spend cap at the provider if the free tier is ever left behind.
 *
 * ── PRECEDENCE ───────────────────────────────────────────────────────────
 *
 * Stored setting first, .env second. The panel is the thing that gets used;
 * the file is the escape hatch for a server. The screen prints which of the
 * two is actually in effect, because a settings page that will not say where
 * a value came from is an afternoon waiting to happen.
 */
class Suggestions
{
    use SettingFields;

    public const PROVIDERS = [
        'gemini' => 'Google Gemini',
        'openai' => 'OpenAI',
    ];

    /**
     * @var array<string, array{key: string, type: string, label: string, help: string}>
     */
    public const FIELDS = [
        'provider' => [
            'key' => 'ai.provider',
            'type' => 'select',
            'label' => 'Provider',
            'help' => 'Which one answers. Both drivers are written, so this is a switch and not a rebuild.',
            'options' => self::PROVIDERS,
        ],

        'gemini_model' => [
            'key' => 'ai.gemini.model',
            'type' => 'text',
            'label' => 'Gemini model',
            'help' => 'The exact id, not a "-latest" alias — an alias is swapped under you and every description written after that comes from a different model. See the ids your key can use: curl -H "x-goog-api-key: KEY" https://generativelanguage.googleapis.com/v1beta/models',
        ],
        'gemini_key' => [
            'key' => 'ai.gemini.key',
            'type' => 'secret',
            'label' => 'Gemini API key',
            'help' => 'aistudio.google.com → Get API key. Free, no card. Encrypted here and never sent back to this screen.',
        ],

        'openai_model' => [
            'key' => 'ai.openai.model',
            'type' => 'text',
            'label' => 'OpenAI model',
            'help' => 'A mini tier is plenty for this. See the ids your key can use: curl -H "Authorization: Bearer KEY" https://api.openai.com/v1/models',
        ],
        'openai_key' => [
            'key' => 'ai.openai.key',
            'type' => 'secret',
            'label' => 'OpenAI API key',
            'help' => 'platform.openai.com → API keys. No signup credits any more; free daily tokens need the data-sharing programme and a card on file.',
        ],
    ];

    /**
     * @var array<string, array{label: string, note: string, fields: array<int, string>}>
     */
    public const SECTIONS = [
        'choice' => [
            'label' => 'Which model answers',
            'note' => 'Both drivers exist so the same twenty sounds can go through each and the tags be read side by side. That comparison is the only honest way to pick one.',
            'fields' => ['provider'],
        ],
        'gemini' => [
            'label' => 'Google Gemini',
            'note' => 'Free without a card. On the free tier Google may use what is sent to improve their products — everything dbelo sends is a filename and a duration for a sound that will be public anyway.',
            'fields' => ['gemini_model', 'gemini_key'],
        ],
        'openai' => [
            'label' => 'OpenAI',
            'note' => 'Free daily tokens exist through an opt-in data-sharing programme, where prompts and answers are used for training, and a card is still required on the account.',
            'fields' => ['openai_model', 'openai_key'],
        ],
    ];

    /* ═══════════════════════════ Reading ═══════════════════════════ */

    /** 'gemini' or 'openai'. Never anything else. */
    public static function provider(): string
    {
        $chosen = self::choice('provider');

        return array_key_exists($chosen, self::PROVIDERS) ? $chosen : 'gemini';
    }

    /**
     * The model name in effect.
     *
     * Setting::read() already falls back to config/dbelo.php, so this is the
     * stored value or the shipped default without a second lookup. The final
     * literal is for the case where somebody deletes the config block: a
     * missing model name would otherwise be sent to the API as an empty
     * string, and the error that comes back says nothing useful.
     */
    public static function model(?string $provider = null): string
    {
        $provider = self::normalise($provider);

        $model = trim(self::text($provider.'_model'));

        if ($model !== '') {
            return $model;
        }

        return $provider === 'openai' ? 'gpt-5-mini' : 'gemini-3.5-flash-lite';
    }

    /**
     * The key in effect: the stored one, or the one from .env.
     *
     * Null when there is none. An absent key is a completely normal state —
     * the suggester simply has nothing to ask — so it is reported, not thrown.
     */
    public static function key(?string $provider = null): ?string
    {
        $provider = self::normalise($provider);

        $stored = self::stored($provider);

        if ($stored !== null) {
            return $stored;
        }

        $fromEnv = trim((string) config('services.ai.'.$provider.'.key'));

        return $fromEnv !== '' ? $fromEnv : null;
    }

    /**
     * Where the key in effect came from: 'settings', 'env' or 'none'.
     *
     * Printed on the screen. Without it, a key left in .env months ago and a
     * key typed into the panel five minutes ago look identical from the
     * outside, and the one that is actually being used is a guess — which is
     * the afternoon where a key is revoked at the provider and the site keeps
     * working, or replaced in the panel and nothing changes.
     */
    public static function keySource(?string $provider = null): string
    {
        $provider = self::normalise($provider);

        if (self::stored($provider) !== null) {
            return 'settings';
        }

        return trim((string) config('services.ai.'.$provider.'.key')) !== '' ? 'env' : 'none';
    }

    public static function ready(?string $provider = null): bool
    {
        return self::key($provider) !== null;
    }

    /** Seconds. Not a preference — see config/services.php. */
    public static function timeout(): int
    {
        return max(5, (int) config('services.ai.timeout', 45));
    }

    /* ═══════════════════════════ Internals ═══════════════════════════ */

    private static function normalise(?string $provider): string
    {
        $provider = strtolower(trim((string) $provider));

        return array_key_exists($provider, self::PROVIDERS) ? $provider : self::provider();
    }

    /**
     * The stored key, decrypted, or null.
     *
     * Setting::cached() and NOT raw(): raw() falls back to config/dbelo.php,
     * and this has to answer "is there a row" rather than "is there a value
     * from anywhere". The distinction is the whole of keySource().
     */
    private static function stored(string $provider): ?string
    {
        $value = trim((string) (Setting::cached()[self::FIELDS[$provider.'_key']['key']] ?? ''));

        return $value === '' ? null : $value;
    }
}
