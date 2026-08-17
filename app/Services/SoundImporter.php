<?php

namespace App\Services;

use App\Models\Category;
use App\Models\License;
use App\Models\Sound;
use App\Models\SoundFile;
use App\Models\User;
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

        $title = $attributes['title']
            ?? Str::headline(pathinfo($originalName, PATHINFO_FILENAME));

        $sound = Sound::create(array_merge([
            'user_id' => $user->id,
            'category_id' => $category?->id,
            'license_id' => $license?->id,
            'type' => 'sfx',
            'title' => $title,
            'slug' => $this->uniqueSlug($title),
            'status' => 'draft',
            'source' => 'original',
        ], $attributes));

        // Stored under the UUID so two uploads named "rain.wav" can never
        // overwrite each other.
        $storedPath = "originals/{$sound->uuid}.{$extension}";

        Storage::disk('sounds_private')->put(
            $storedPath,
            file_get_contents($sourcePath)
        );

        SoundFile::create([
            'sound_id' => $sound->id,
            'purpose' => 'original',
            'format' => $extension,
            'disk' => 'sounds_private',
            'path' => $storedPath,
            'size_bytes' => filesize($sourcePath),
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
