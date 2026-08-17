<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class SoundFile extends Model
{
    protected $guarded = [];

    public function sound(): BelongsTo
    {
        return $this->belongsTo(Sound::class);
    }

    public function isPublic(): bool
    {
        return $this->disk === 'public';
    }

    /**
     * Only public previews have a direct URL. Downloadable files live on a
     * private disk and are served through a controller that checks quota.
     */
    public function url(): ?string
    {
        return $this->isPublic()
            ? Storage::disk($this->disk)->url($this->path)
            : null;
    }

    public function sizeForHumans(): string
    {
        $mb = $this->size_bytes / 1_048_576;

        return $mb < 1
            ? number_format($this->size_bytes / 1024, 0).' KB'
            : number_format($mb, 1).' MB';
    }
}
