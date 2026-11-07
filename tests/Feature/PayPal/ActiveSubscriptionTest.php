<?php

namespace Tests\Feature\PayPal;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Which plan an account is actually living under.
 *
 * ── WHY THIS DESERVES ITS OWN FILE ──────────────────────────────────────
 *
 * Because an account can hold two plans at once and nothing warns you.
 * Buying Pro does not end a pass that was already paid for, so somebody
 * who buys a pass at ten and subscribes at noon genuinely holds both.
 *
 * User::activeSubscription() picks between them, and it is the same method
 * canDownload() enforces with. Get it wrong and a paying subscriber
 * silently loses what they paid for — no error, no log, nothing to notice.
 */
class ActiveSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    protected Plan $free;

    protected Plan $pass;

    protected Plan $pro;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->free = Plan::create([
            'name' => 'Free', 'slug' => 'free', 'price_cents' => 0, 'interval' => 'month',
            'daily_download_limit' => 10, 'allows_premium' => false, 'is_active' => true,
        ]);

        $this->pass = Plan::create([
            'name' => 'Day Pass', 'slug' => 'day-pass', 'price_cents' => 900, 'interval' => 'day',
            'daily_download_limit' => 50, 'allows_premium' => true, 'is_active' => true,
        ]);

        $this->pro = Plan::create([
            'name' => 'Pro', 'slug' => 'pro-monthly', 'price_cents' => 1400, 'interval' => 'month',
            'daily_download_limit' => null, 'allows_premium' => true, 'is_active' => true,
        ]);

        $this->user = User::factory()->create();
    }

    protected function give(Plan $plan, string $externalId, ?string $startsAt, ?string $endsAt): Subscription
    {
        return Subscription::create([
            'user_id' => $this->user->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'gateway' => 'paypal',
            'external_id' => $externalId,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]);
    }

    /** The ordinary case: a pass bought first, Pro bought after. */
    public function test_pro_wins_when_it_was_bought_after_the_pass(): void
    {
        $this->give($this->pass, 'CAP-1', now()->subHours(2)->toDateTimeString(), now()->addHours(22)->toDateTimeString());
        $this->give($this->pro, 'I-PRO', now()->toDateTimeString(), null);

        $this->assertSame('Pro', $this->user->currentPlan()->name);
    }

    /**
     * ── THE BUG ─────────────────────────────────────────────────────────
     *
     * This used to order by starts_at: most recently started wins. It gave
     * the right answer for the only case anybody had tried, because Pro is
     * always bought after the pass — correct by accident.
     *
     * The other direction is where it bites: a pass granted by hand to
     * somebody who already has Pro, a start_time that arrives out of order,
     * a correction in the database. Any of those made the pass the newest
     * row, and a Pro subscriber silently dropped from unlimited downloads
     * to fifty a day.
     */
    public function test_pro_still_wins_when_the_pass_is_the_newer_row(): void
    {
        $this->give($this->pro, 'I-PRO', now()->subHours(2)->toDateTimeString(), null);
        $this->give($this->pass, 'CAP-1', now()->toDateTimeString(), now()->addDay()->toDateTimeString());

        $this->assertSame('Pro', $this->user->currentPlan()->name);
        $this->assertNull($this->user->currentPlan()->daily_download_limit);
    }

    /** A pass beats free, which is the whole reason somebody bought it. */
    public function test_a_pass_wins_over_free(): void
    {
        $this->give($this->free, 'FREE-1', now()->subMonth()->toDateTimeString(), null);
        $this->give($this->pass, 'CAP-1', now()->toDateTimeString(), now()->addDay()->toDateTimeString());

        $this->assertSame('Day Pass', $this->user->currentPlan()->name);
    }

    /** An expired pass is not a plan, however recently it started. */
    public function test_an_expired_pass_is_ignored(): void
    {
        $this->give($this->pass, 'CAP-1', now()->subDays(2)->toDateTimeString(), now()->subDay()->toDateTimeString());

        $this->assertSame('Free', $this->user->currentPlan()->name);
    }

    /** No rows at all is a fresh account, not an error. */
    public function test_an_account_with_nothing_falls_back_to_free(): void
    {
        $this->assertSame('Free', $this->user->currentPlan()->name);
    }

    /**
     * Cancelling records the intention and keeps the time already paid
     * for. Switching somebody off on the day they cancel takes away weeks
     * they bought, and is the most common way a subscription integration
     * robs its own customers.
     */
    public function test_a_cancelled_subscription_keeps_working_until_its_end_date(): void
    {
        $subscription = $this->give($this->pro, 'I-PRO', now()->subMonth()->toDateTimeString(), now()->addWeek()->toDateTimeString());
        $subscription->forceFill(['cancelled_at' => now()])->save();

        $this->assertSame('Pro', $this->user->currentPlan()->name);
    }

    /**
     * ── THE BUG ─────────────────────────────────────────────────────────
     *
     * passEndsAt() was typed as Illuminate\Support\Carbon. This application
     * uses immutable dates, so a datetime cast hands back a CarbonImmutable
     * and the call threw a TypeError. $from is only ever non-null when
     * somebody already holds a live pass — so this was the stacking path,
     * and the second pass anybody bought would have exploded AFTER their
     * money had been taken.
     */
    public function test_a_pass_can_be_measured_from_an_existing_subscriptions_end_date(): void
    {
        $live = $this->give($this->pass, 'CAP-1', now()->toDateTimeString(), now()->addDay()->toDateTimeString());

        // ends_at comes back as whatever date class the app is configured
        // for; passEndsAt() must accept it without caring which.
        $end = $this->pass->passEndsAt($live->ends_at);

        /*
         * Compared as dates, not as a count of days.
         *
         * The first version asserted `assertSame(1, ...->diffInDays(...))`
         * and failed on `1.0 is not identical to 1`: Carbon returns a
         * float. Fixing the type would have worked and would still have
         * been measuring the wrong thing — what this test is about is
         * WHICH MOMENT the pass ends, and that is worth stating exactly.
         */
        $this->assertTrue($end->greaterThan($live->ends_at));
        $this->assertSame(
            $live->ends_at->copy()->addDay()->toDateTimeString(),
            $end->toDateTimeString(),
        );
    }

    /** The duration comes from the column, so a week pass needs no code. */
    public function test_the_pass_length_is_read_from_the_interval(): void
    {
        $week = Plan::create([
            'name' => 'Week Pass', 'slug' => 'week-pass', 'price_cents' => 2500, 'interval' => 'week',
            'daily_download_limit' => 50, 'allows_premium' => true, 'is_active' => true,
        ]);

        $from = now();

        $this->assertSame(
            $from->copy()->addDay()->toDateTimeString(),
            $this->pass->passEndsAt($from)->toDateTimeString(),
        );

        $this->assertSame(
            $from->copy()->addWeek()->toDateTimeString(),
            $week->passEndsAt($from)->toDateTimeString(),
        );
    }
}
