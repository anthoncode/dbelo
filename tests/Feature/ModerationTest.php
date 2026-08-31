<?php

namespace Tests\Feature;

use App\Models\Sound;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModerationTest extends TestCase
{
    use RefreshDatabase;

    protected function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => $role])->save();

        return $user;
    }

    protected function admin(): User
    {
        return $this->userWithRole(User::ROLE_ADMIN);
    }

    public function test_non_admins_cannot_reach_moderation(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('moderate'))
            ->assertStatus(403);
    }

    public function test_admins_can_reach_moderation(): void
    {
        $this->actingAs($this->admin())
            ->get(route('moderate'))
            ->assertOk();
    }

    public function test_only_contributors_can_reach_the_uploader(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('upload'))
            ->assertStatus(403);

        $collaborator = $this->userWithRole(User::ROLE_COLLABORATOR);

        $this->actingAs($collaborator)->get(route('upload'))->assertOk();
    }

    public function test_a_published_sound_keeps_its_slug_when_retitled(): void
    {
        $sound = Sound::create([
            'user_id' => User::factory()->create()->id,
            'title' => 'Original Title',
            'slug' => 'original-title',
            'status' => 'published',
            'published_at' => now(),
            'duration_ms' => 1000,
        ]);

        $sound->update(['title' => 'A Completely New Title']);

        // The URL is frozen once public: bookmarks and rankings depend on it.
        $this->assertSame('original-title', $sound->fresh()->slug);
    }
}
