<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class PostTag extends Model
{
    protected $guarded = [];

    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class, 'post_post_tag');
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Tags are typed freehand while writing, so the same idea arrives as
     * "Field recording", "field-recording" and "Field Recording". Matching
     * on the slug collapses all three into one row.
     */
    public static function fromName(string $name): self
    {
        $name = trim($name);

        return static::firstOrCreate(
            ['slug' => Str::slug($name)],
            ['name' => $name],
        );
    }
}
