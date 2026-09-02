<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Key-value settings, read on almost every request.
 *
 * Three rules, all of them the difference between this being useful and
 * being a tax:
 *
 *   1. THE WHOLE SET IS CACHED UNDER ONE KEY. Settings are read all over the
 *      place; a query per lookup is twenty queries a page. One cache entry
 *      holds the lot and is dropped whenever any row is written.
 *
 *   2. IT CACHES A PLAIN ARRAY. Never models, never an Eloquent collection —
 *      those serialise with NUL bytes in their protected-property keys, and
 *      those do not survive a MySQL text column. It comes back broken on the
 *      SECOND read, which is the hardest possible moment to work out why.
 *
 *   3. DEFAULTS COME FROM config/dbelo.php. The table holds only what
 *      somebody changed, so an empty table is a working install and a new
 *      default ships without a data migration.
 */
class Setting extends Model
{
    protected $guarded = [];

    public const CACHE_KEY = 'settings.all';

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(self::CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::CACHE_KEY));
    }

    /**
     * The whole set, cached.
     *
     * NOT called all() or get(). Model::all() is a real framework method and
     * the query builder owns get(), so taking either name here overrides
     * something the rest of Laravel may call — the same trap that turned a
     * model method named where() into a fatal error across the whole panel.
     * A model method must never take a name the framework already uses.
     *
     * @return array<string, string|null>
     */
    public static function cached(): array
    {
        try {
            /*
             * The cache is consulted BEFORE Schema::hasTable, not after.
             *
             * hasTable is a real query, and this method is now read on every
             * web request — putting it first would mean one guaranteed query
             * per request to protect against a state that lasts until the
             * first migrate. The guard still runs on a cache miss, which is
             * where it is needed.
             *
             * The missing-table answer is deliberately NOT cached either. An
             * empty array stored for an hour would mean the settings look
             * empty for an hour after the migration finally runs, which is
             * an hour of a screen lying about what is saved.
             */
            $cached = Cache::get(self::CACHE_KEY);

            if (is_array($cached)) {
                return $cached;
            }

            if (! Schema::hasTable('settings')) {
                return [];
            }

            $out = [];

            foreach (static::query()->get(['key', 'value', 'is_encrypted']) as $row) {
                $out[$row->key] = $row->is_encrypted && filled($row->value)
                    ? rescue(fn () => Crypt::decryptString($row->value), null, false)
                    : $row->value;
            }

            Cache::put(self::CACHE_KEY, $out, now()->addHour());

            return $out;
        } catch (Throwable) {
            // Before the migration, or with the database down. Falling back
            // to config defaults keeps the site working instead of turning a
            // missing table into a white screen.
            return [];
        }
    }

    /**
     * A setting, or the config default behind it.
     *
     * Dotted keys map onto config/dbelo.php, so `backup.frequency` is
     * config('dbelo.backup.frequency') until somebody changes it.
     */
    public static function read(string $key, mixed $fallback = null): mixed
    {
        $stored = static::cached()[$key] ?? null;

        if ($stored !== null && $stored !== '') {
            return $stored;
        }

        return config("dbelo.{$key}", $fallback);
    }

    public static function put(string $key, mixed $value, string $group = 'general', bool $encrypted = false): void
    {
        static::updateOrCreate(
            ['key' => $key],
            [
                'value' => $encrypted && filled($value) ? Crypt::encryptString((string) $value) : $value,
                'group' => $group,
                'is_encrypted' => $encrypted,
            ],
        );
    }
}
