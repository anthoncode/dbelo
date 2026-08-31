<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Media extends Model
{
    protected $table = 'media';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['variants' => 'array'];
    }

    protected static function booted(): void
    {
        static::creating(function (Media $media) {
            $media->uuid ??= (string) Str::uuid();
        });

        // An image nobody points at is dead weight on disk. Deleting the row
        // takes every generated size with it.
        static::deleting(function (Media $media) {
            $disk = Storage::disk($media->disk);

            $disk->delete($media->path);

            foreach ($media->variants ?? [] as $path) {
                $disk->delete($path);
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function url(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }

    /** The `srcset` attribute, so the browser picks the size it needs. */
    public function srcset(): string
    {
        $sources = collect($this->variants ?? [])
            ->map(fn ($path, $width) => Storage::disk($this->disk)->url($path)." {$width}w");

        return $sources
            ->push($this->url()." {$this->width}w")
            ->join(', ');
    }

    public function sizeForHumans(): string
    {
        return $this->size > 1048576
            ? number_format($this->size / 1048576, 1).' MB'
            : round($this->size / 1024).' KB';
    }

    /** Ready to paste into the body: markdown, with the alt text filled in. */
    public function markdown(): string
    {
        return '!['.($this->alt ?: $this->name).']('.$this->url().')';
    }
}
