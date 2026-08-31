<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Sound;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What reaches the search index. Getting this wrong leaks drafts and
 * rejected uploads into the public catalogue.
 */
class SoundSearchabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function sound(array $attributes = []): Sound
    {
        return Sound::create(array_merge([
            'user_id' => User::factory()->create()->id,
            'title' => 'Wooden Door Creak',
            'slug' => 'wooden-door-creak-'.uniqid(),
            'status' => 'draft',
            'duration_ms' => 3400,
        ], $attributes));
    }

    public function test_only_published_sounds_are_searchable(): void
    {
        $this->assertFalse($this->sound(['status' => 'draft'])->shouldBeSearchable());
        $this->assertFalse($this->sound(['status' => 'pending'])->shouldBeSearchable());
        $this->assertFalse($this->sound(['status' => 'rejected'])->shouldBeSearchable());

        $this->assertTrue(
            $this->sound(['status' => 'published', 'published_at' => now()])->shouldBeSearchable()
        );
    }

    public function test_published_without_a_date_is_not_searchable(): void
    {
        $this->assertFalse(
            $this->sound(['status' => 'published', 'published_at' => null])->shouldBeSearchable()
        );
    }

    public function test_the_index_payload_flattens_the_relationships(): void
    {
        $parent = Category::create(['name' => 'Foley', 'slug' => 'foley']);
        $child = Category::create(['name' => 'Wood', 'slug' => 'foley-wood', 'parent_id' => $parent->id]);

        $sound = $this->sound([
            'status' => 'published',
            'published_at' => now(),
            'category_id' => $child->id,
        ]);

        $payload = $sound->toSearchableArray();

        $this->assertSame('Wooden Door Creak', $payload['title']);
        $this->assertSame('Wood', $payload['category']);
        $this->assertSame('foley-wood', $payload['category_slug']);
        // Filtering by the parent must also reach its children.
        $this->assertSame('foley', $payload['parent_category_slug']);
        $this->assertIsInt($payload['duration_ms']);
    }
}
