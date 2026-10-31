<?php

namespace Tests\Feature;

use App\Models\License;
use App\Models\Plan;
use App\Models\Sound;
use App\Models\SoundFile;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Downloads;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The download gate is where the business model lives. If these break,
 * dbelo gives its catalogue away.
 */
class DownloadQuotaTest extends TestCase
{
    use RefreshDatabase;

    protected Plan $freePlan;

    protected Plan $proPlan;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('sounds_private');
        Storage::fake('public');

        $this->freePlan = Plan::create([
            'name' => 'Free', 'slug' => 'free', 'price_cents' => 0,
            'daily_download_limit' => 3, 'allows_premium' => false, 'is_active' => true,
        ]);

        $this->proPlan = Plan::create([
            'name' => 'Pro', 'slug' => 'pro-monthly', 'price_cents' => 900,
            'daily_download_limit' => null, 'allows_premium' => true, 'is_active' => true,
        ]);
    }

    protected function publishedSound(array $attributes = []): Sound
    {
        $sound = Sound::create(array_merge([
            'user_id' => User::factory()->create()->id,
            'title' => 'Test Sound',
            'slug' => 'test-sound-'.uniqid(),
            'status' => 'published',
            'published_at' => now(),
            'duration_ms' => 2000,
        ], $attributes));

        Storage::disk('sounds_private')->put("downloads/{$sound->uuid}.mp3", 'audio-bytes');

        SoundFile::create([
            'sound_id' => $sound->id,
            'purpose' => 'download',
            'format' => 'mp3',
            'disk' => 'sounds_private',
            'path' => "downloads/{$sound->uuid}.mp3",
            'size_bytes' => 11,
        ]);

        return $sound;
    }

    public function test_guests_cannot_download(): void
    {
        $sound = $this->publishedSound();

        $this->get(Downloads::url($sound))->assertRedirect(route('login'));
    }

    /**
     * The URL cannot be constructed, only issued.
     *
     * This is the one that matters against a scraper. Every slug in the
     * catalogue is published in the sitemap, so /sounds/{slug}/download was
     * guessable for every sound at once — and the first 471 rows in the
     * downloads table, none of them with an account, are what that cost.
     *
     * Asserting the REDIRECT and not a 403 on purpose: an expired link is
     * almost always a person who left a tab open, and sending them to the
     * sound page puts a fresh button under their cursor. The assertion is
     * the behaviour, so a later change to 403 has to be a decision somebody
     * makes rather than something that slips through.
     */
    public function test_an_unsigned_download_url_does_not_serve_the_file(): void
    {
        $user = User::factory()->create();
        $sound = $this->publishedSound();

        $this->actingAs($user)
            ->get(route('sounds.download', $sound))
            ->assertRedirect(route('sounds.show', $sound));

        $this->assertSame(0, $user->fresh()->downloadsToday());
        $this->assertSame(0, $sound->fresh()->downloads_count);
    }

    /**
     * And an expired one behaves the same way.
     *
     * Travelling past Downloads::LINK_HOURS rather than hand-building a
     * stale signature: the constant is the thing under test, so the test
     * has to read it. Written out, the two would disagree the first time
     * the window changed.
     */
    public function test_an_expired_download_link_sends_you_back_to_the_sound(): void
    {
        $user = User::factory()->create();
        $sound = $this->publishedSound();

        $url = Downloads::url($sound);

        $this->travel(Downloads::LINK_HOURS + 1)->hours();

        $this->actingAs($user)
            ->get($url)
            ->assertRedirect(route('sounds.show', $sound));
    }

    public function test_free_user_can_download_up_to_the_daily_limit(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($user)
                ->get(Downloads::url($this->publishedSound()))
                ->assertOk();
        }

        $this->assertSame(3, $user->fresh()->downloadsToday());
    }

    public function test_free_user_is_blocked_past_the_daily_limit(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($user)->get(Downloads::url($this->publishedSound()));
        }

        $this->actingAs($user)
            ->get(Downloads::url($this->publishedSound()))
            ->assertStatus(429);
    }

    public function test_subscriber_has_no_daily_limit(): void
    {
        $user = User::factory()->create();

        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $this->proPlan->id,
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
        ]);

        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($user)
                ->get(Downloads::url($this->publishedSound()))
                ->assertOk();
        }
    }

    public function test_free_user_cannot_download_a_premium_sound(): void
    {
        $user = User::factory()->create();
        $sound = $this->publishedSound(['is_premium' => true]);

        $this->actingAs($user)
            ->get(Downloads::url($sound))
            ->assertStatus(403);
    }

    public function test_unpublished_sounds_are_not_downloadable(): void
    {
        $user = User::factory()->create();
        $sound = $this->publishedSound(['status' => 'pending', 'published_at' => null]);

        $this->actingAs($user)
            ->get(Downloads::url($sound))
            ->assertNotFound();
    }

    public function test_download_freezes_the_license_text(): void
    {
        $license = License::create([
            'name' => 'dbelo Standard', 'slug' => 'std', 'version' => '1.0',
            'summary' => 'Original terms', 'requires_attribution' => true,
            'allows_commercial' => true, 'allows_derivatives' => true,
        ]);

        $user = User::factory()->create();
        $sound = $this->publishedSound(['license_id' => $license->id]);

        $this->actingAs($user)->get(Downloads::url($sound))->assertOk();

        // Terms change a year later...
        $license->update(['summary' => 'Completely different terms', 'version' => '2.0']);

        $snapshot = $user->downloads()->first()->license_snapshot;

        // ...but the user keeps what they accepted.
        $this->assertSame('Original terms', $snapshot['summary']);
        $this->assertSame('1.0', $snapshot['version']);
    }
}
