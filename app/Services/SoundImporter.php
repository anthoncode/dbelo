<?php

namespace App\Services;

use App\Models\Category;
use App\Models\License;
use App\Models\Sound;
use App\Models\SoundFile;
use App\Models\User;
use App\Support\FilenameMeta;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Creates a Sound from a file on disk and stores the master copy.
 *
 * Shared by the web uploader and the artisan command so both paths behave
 * identically: same slugs, same storage layout, same defaults.
 *
 * Note this does NOT process the audio. The caller decides whether to
 * queue ProcessSoundUpload or run it synchronously.
 */
class SoundImporter
{
    public function import(
        string $sourcePath,
        string $originalName,
        User $user,
        ?Category $category = null,
        ?License $license = null,
        array $attributes = [],
    ): Sound {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        // FilenameMeta rather than a bare headline(): it also strips take
        // numbers and understands the UCS convention, where the readable
        // name is the second underscore segment and not the whole string.
        $title = $attributes['title']
            ?? FilenameMeta::parse($originalName)['title'];

        // 'checksum' and 'title' are instructions to this method, not
        // columns on sounds. Anything else in $attributes is passed through.
        $columns = array_diff_key($attributes, array_flip(['checksum']));

        $sound = Sound::create(array_merge([
            'user_id' => $user->id,
            'category_id' => $category?->id,
            'license_id' => $license?->id,
            'type' => 'sfx',
            'title' => $title,
            'slug' => $this->uniqueSlug($title),
            'status' => 'draft',
            'source' => 'original',
        ], $columns));

        // Stored under the UUID so two uploads named "rain.wav" can never
        // overwrite each other.
        $storedPath = "originals/{$sound->uuid}.{$extension}";
        $disk = config('dbelo.storage.master');

        // Streamed, not read into a string. file_get_contents() on a 200 MB
        // master needs 200 MB of PHP memory, and a bulk import doing that
        // fifty times in one request is how you meet memory_limit.
        $stream = fopen($sourcePath, 'rb');

        try {
            Storage::disk($disk)->put($storedPath, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        SoundFile::create([
            'sound_id' => $sound->id,
            'purpose' => 'original',
            'format' => $extension,
            'disk' => $disk,
            'path' => $storedPath,
            'size_bytes' => filesize($sourcePath),
            // Reads the file a second time, but hash_file streams it, and
            // this is what makes "have we already got this?" answerable.
            'checksum' => $attributes['checksum'] ?? hash_file('sha256', $sourcePath),
        ]);

        return $sound;
    }

    public function uniqueSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'sound';
        $slug = $base;
        $i = 2;

        while (Sound::withTrashed()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }
}
