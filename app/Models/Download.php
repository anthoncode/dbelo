<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Download extends Model
{
    protected $guarded = [];

    /** This table only records created_at: a download is a fact, never edited. */
    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'license_snapshot' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sound(): BelongsTo
    {
        return $this->belongsTo(Sound::class);
    }

    public function soundFile(): BelongsTo
    {
        return $this->belongsTo(SoundFile::class);
    }

    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }
}
