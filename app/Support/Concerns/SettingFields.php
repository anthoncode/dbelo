<?php

namespace App\Support\Concerns;

use App\Models\Setting;
use Illuminate\Support\HtmlString;

/**
 * Reading a declared set of settings, in one place.
 *
 * Extracted the moment there was a second screen. Homepage and Security each
 * declare a FIELDS map — short name to setting key, type and label — and
 * every one of them needs the same six accessors. Written twice they would
 * disagree the first time one was fixed, and the disagreement would take the
 * form of a control that works on one screen and not the other.
 *
 * The using class must declare:
 *
 *   public const FIELDS = [
 *       'short_name' => ['key' => 'group.dotted.key', 'type' => …, …],
 *   ];
 *
 * Types: bool · number · text · rich · select · secret · url · date
 */
trait SettingFields
{
    /** The stored value, or the default from config/dbelo.php. */
    public static function raw(string $field): mixed
    {
        return Setting::read(self::FIELDS[$field]['key']);
    }

    /**
     * A toggle.
     *
     * Settings come back from the table as strings. PHP reads '0' as false
     * only because it is a string; casting explicitly means a value written
     * as 'false' or 'off' by some future importer still lands right way up.
     */
    public static function flag(string $field): bool
    {
        return filter_var(self::raw($field), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE)
            ?? (bool) (self::FIELDS[$field]['default_on'] ?? false);
    }

    public static function number(string $field): int
    {
        return (int) self::raw($field);
    }

    public static function text(string $field): string
    {
        return (string) self::raw($field);
    }

    /** One of the declared options, never whatever happens to be stored. */
    public static function choice(string $field): string
    {
        $value = self::text($field);
        $options = self::FIELDS[$field]['options'] ?? [];

        return array_key_exists($value, $options)
            ? $value
            : (string) array_key_first($options);
    }

    /** Escaped, with *asterisks* turned into the highlight. See Copy. */
    public static function rich(string $field): HtmlString
    {
        return \App\Support\Copy::rich(self::text($field));
    }

    /**
     * The default, in the same shape the form stores.
     *
     * Booleans arrive from config as real booleans and go into the table as
     * '1' or '0'; comparing them needs both sides in one alphabet. This is
     * what lets a screen say "you chose what the default already says, so
     * nothing needs storing" instead of writing a row that changes nothing
     * and an audit entry about it.
     */
    public static function defaultValue(string $field): string
    {
        $value = config('dbelo.'.self::FIELDS[$field]['key']);

        if ((self::FIELDS[$field]['type'] ?? '') === 'bool') {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }

    /**
     * Does a secret have a value stored?
     *
     * Presence only. The value itself is never returned to a screen — see
     * the secret handling in the Security settings component.
     */
    public static function hasSecret(string $field): bool
    {
        return filled(Setting::cached()[self::FIELDS[$field]['key']] ?? null);
    }
}
