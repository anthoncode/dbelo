<?php

namespace App\Support;

/**
 * What this server will actually accept, in bytes.
 *
 * Four separate ceilings decide whether an upload survives, they live in
 * three different places, and every one of them fails quietly:
 *
 *   livewire   temporary_file_upload.rules — 12 MB by default, and the
 *              default applies whenever config/livewire.php has not been
 *              published. A file over it is rejected and takes the whole
 *              batch with it.
 *   php        upload_max_filesize — per file
 *   php        post_max_size — the WHOLE request, which for Livewire means
 *              every file selected at once, added together
 *   php        max_file_uploads — 20 by default; the 21st is dropped with
 *              no error at all
 *
 * Reading them out loud is the difference between "the upload broke" and
 * "that file is 40 MB and this server stops at 12".
 */
class UploadLimits
{
    /**
     * Extensions accepted at the door.
     *
     * Wide on purpose. ffmpeg decodes all of these and the real gate is
     * ffprobe inside ProcessSoundUpload, which fails with a message that
     * says what was actually wrong. A short list here only means turning
     * away files that would have worked.
     *
     * Matched on the EXTENSION, not the MIME type. Audio MIME detection is
     * genuinely unreliable — the same .wav is reported as audio/wav,
     * audio/x-wav, audio/vnd.wave or application/octet-stream depending on
     * which tool wrote it, and .m4a frequently comes through as video/mp4.
     * A MIME allowlist rejects real recordings for reasons nobody can guess
     * from the error message.
     */
    public const FORMATS = [
        'wav', 'wave', 'bwf',
        'mp3',
        'aif', 'aiff', 'aifc',
        'flac',
        'ogg', 'oga', 'opus',
        'm4a', 'm4b', 'mp4', 'aac',
        'wma', 'caf', 'au', 'snd',
        'mka', 'ape', 'wv', 'amr', '3gp',
    ];

    public static function accepts(string $filename): bool
    {
        return in_array(
            strtolower(pathinfo($filename, PATHINFO_EXTENSION)),
            self::FORMATS,
            true,
        );
    }

    /** For the file input's accept attribute. */
    public static function acceptAttribute(): string
    {
        return '.'.implode(',.', self::FORMATS);
    }

    /** Livewire's own cap on one temporary upload. */
    public static function livewireBytes(): int
    {
        $rules = config('livewire.temporary_file_upload.rules');

        // Null means Livewire falls back to its built-in ['required', 'file',
        // 'max:12288'] — the case that bites, because nothing in the project
        // mentions 12 MB anywhere.
        $rules = $rules ?: ['required', 'file', 'max:12288'];

        foreach ((array) $rules as $rule) {
            if (is_string($rule) && str_starts_with($rule, 'max:')) {
                return (int) substr($rule, 4) * 1024;
            }
        }

        return 12288 * 1024;
    }

    public static function perFileBytes(): int
    {
        return min(self::ini('upload_max_filesize'), self::livewireBytes());
    }

    public static function perRequestBytes(): int
    {
        return self::ini('post_max_size');
    }

    public static function maxFiles(): int
    {
        return (int) (ini_get('max_file_uploads') ?: 20);
    }

    /**
     * Which php.ini is actually in force.
     *
     * There are usually several on a machine and only one is loaded, so
     * "edit php.ini" is not an instruction — this is. Same answer as
     * `php --ini`, printed where the problem is visible.
     */
    public static function iniFile(): ?string
    {
        return php_ini_loaded_file() ?: null;
    }

    /**
     * Is the ceiling too low to be usable for audio?
     *
     * PHP ships with upload_max_filesize = 2M and post_max_size = 8M, which
     * are fine for a form with an avatar on it and useless for a sound
     * library. Nothing warns you: the upload just refuses, and the number it
     * refuses at is never mentioned anywhere in the application.
     */
    public static function tooLowForAudio(): bool
    {
        return self::perFileBytes() < 25 * 1024 ** 2;
    }

    /**
     * Everything the upload screen needs, ready to hand to JavaScript.
     */
    public static function all(): array
    {
        return [
            'formats' => self::FORMATS,
            'ini_file' => self::iniFile(),
            'too_low' => self::tooLowForAudio(),
            'per_file' => self::perFileBytes(),
            'per_request' => self::perRequestBytes(),
            'max_files' => self::maxFiles(),
            'livewire' => self::livewireBytes(),
            'upload_max_filesize' => self::ini('upload_max_filesize'),
            'post_max_size' => self::ini('post_max_size'),
        ];
    }

    /** "512M" and "8G" into bytes. */
    public static function ini(string $key): int
    {
        $value = trim((string) ini_get($key));

        if ($value === '') {
            return PHP_INT_MAX;
        }

        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }

    public static function forHumans(int $bytes): string
    {
        if ($bytes >= 1024 ** 3) {
            return round($bytes / 1024 ** 3, 1).' GB';
        }

        return round($bytes / 1024 ** 2).' MB';
    }
}
