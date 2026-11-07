<?php

namespace Tests\Feature\PayPal;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PayPal\OrderEvents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Buying a pass, end to end.
 *
 * Four of the five bugs that reached production on launch day are in here
 * as named tests. Each one was found by somebody paying real attention to
 * a sandbox account and waiting for a deployment; each would have been
 * found here in under a second.
 */
class PassCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected Plan $free;

    protected Plan $pass;

    protected Plan $pro;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.paypal.mode' => 'sandbox',
            'services.paypal.client_id' => 'test-client-id',
            'services.paypal.client_secret' => 'test-secret',
            'services.paypal.webhook_id' => 'WH-TEST',
            'app.url' => 'https://dbelo.com',
        ]);

        /*
         * A public host, and not decoration.
         *
         * route() builds the return URL from the CURRENT REQUEST's host,
         * and the checkout refuses to start when PayPal could not come back
         * to it. The test host is `localhost`, which PublicUrl correctly
         * rejects — so without this every test here would exercise the
         * refusal branch and prove nothing.
         */
        URL::forceRootUrl('https://dbelo.com');

        $this->free = Plan::create([
            'name' => 'Free', 'slug' => 'free', 'price_cents' => 0, 'interval' => 'month',
            'daily_download_limit' => 10, 'allows_premium' => false, 'is_active' => true, 'sort_order' => 1,
        ]);

        $this->pass = Plan::create([
            'name' => 'Day Pass', 'slug' => 'day-pass', 'price_cents' => 900, 'interval' => 'day',
            'daily_download_limit' => 50, 'allows_premium' => true, 'is_active' => true, 'sort_order' => 2,
        ]);

        $this->pro = Plan::create([
            'name' => 'Pro', 'slug' => 'pro-monthly', 'price_cents' => 1400, 'interval' => 'month',
            'daily_download_limit' => null, 'allows_premium' => true, 'is_active' => true, 'sort_order' => 3,
        ]);

        $this->user = User::factory()->create(['email_verified_at' => now()]);
    }

    protected function fakeOrderCreated(): void
    {
        Http::fake([
            '*/v1/oauth2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 32400]),
            '*/v2/checkout/orders' => Http::response([
                'id' => 'ORDER-1',
                'status' => 'CREATED',
                'links' => [
                    ['rel' => 'self', 'href' => 'https://api/orders/ORDER-1'],
                    ['rel' => 'approve', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER-1'],
                ],
            ], 201),
        ]);
    }

    /** What PayPal returns once the buyer has approved and we capture. */
    protected function capturedOrder(string $customId = '', string $captureId = 'CAPTURE-1'): array
    {
        return [
            'id' => 'ORDER-1',
            'status' => 'COMPLETED',
            'purchase_units' => [[
                'reference_id' => 'day-pass',
                'custom_id' => $customId ?: $this->user->id.':day-pass',
                'payments' => ['captures' => [[
                    'id' => $captureId,
                    'status' => 'COMPLETED',
                    // NOTE: no reference_id. PayPal's capture schema has no
                    // such field, and assuming it did is the bug below.
                    'custom_id' => $customId ?: $this->user->id.':day-pass',
                    'amount' => ['currency_code' => 'USD', 'value' => '9.00'],
                    'seller_receivable_breakdown' => [
                        'gross_amount' => ['currency_code' => 'USD', 'value' => '9.00'],
                        'paypal_fee' => ['currency_code' => 'USD', 'value' => '0.61'],
                    ],
                    'create_time' => now()->toIso8601ZuluString(),
                ]]],
            ]],
        ];
    }

    /* ═══════════════════════════ Starting ═══════════════════════════ */

    /**
     * ── THE BUG ─────────────────────────────────────────────────────────
     *
     * An order carries no plan id — that idea only exists for
     * subscriptions — so the slug rides in reference_id. And custom_id
     * carries BOTH the user and the slug, because PayPal copies custom_id
     * onto the capture and does not copy reference_id. Without the slug in
     * custom_id, a PAYMENT.CAPTURE.COMPLETED webhook knows who paid and not
     * what for, and files the payment as an orphan.
     */
    public function test_the_order_carries_both_the_buyer_and_the_plan(): void
    {
        $this->fakeOrderCreated();

        $this->actingAs($this->user)
            ->post(route('pass.start', $this->pass))
            ->assertRedirect();

        Http::assertSent(function ($request) {
            if (! str_ends_with($request->url(), '/v2/checkout/orders')) {
                return false;
            }

            $unit = $request->data()['purchase_units'][0] ?? [];

            return ($unit['reference_id'] ?? null) === 'day-pass'
                && ($unit['custom_id'] ?? null) === $this->user->id.':day-pass';
        });
    }

    /**
     * ── THE BUG ─────────────────────────────────────────────────────────
     *
     * On a development machine the return URL is http://dbelo.test/...,
     * and `.test` is reserved so that it can never resolve on the public
     * internet. PayPal simply hung on its own approval screen, with no
     * error anywhere, and the cause took an hour to find.
     */
    public function test_it_refuses_to_start_when_paypal_could_not_come_back(): void
    {
        URL::forceRootUrl('http://dbelo.test');
        Http::fake();

        $this->actingAs($this->user)
            ->post(route('pass.start', $this->pass))
            ->assertRedirect(route('plan.show'))
            ->assertSessionHas('checkout_error');

        // Nothing was even attempted at PayPal.
        Http::assertNothingSent();
    }

    /** Selling a day of what somebody already has every day is theft by UI. */
    public function test_it_refuses_a_pass_to_somebody_on_a_recurring_plan(): void
    {
        Subscription::create([
            'user_id' => $this->user->id, 'plan_id' => $this->pro->id,
            'status' => 'active', 'gateway' => 'paypal', 'external_id' => 'I-PRO',
            'starts_at' => now()->subDay(), 'ends_at' => null,
        ]);

        Http::fake();

        $this->actingAs($this->user)
            ->post(route('pass.start', $this->pass))
            ->assertSessionHas('checkout_error');

        Http::assertNothingSent();
    }

    /** A subscription is not a thing you buy once. */
    public function test_it_refuses_a_recurring_plan(): void
    {
        Http::fake();

        $this->actingAs($this->user)
            ->post(route('pass.start', $this->pro))
            ->assertSessionHas('checkout_error');

        Http::assertNothingSent();
    }

    public function test_a_guest_cannot_start_a_purchase(): void
    {
        Http::fake();

        $this->post(route('pass.start', $this->pass))->assertRedirect(route('login'));

        Http::assertNothingSent();
    }

    /* ═══════════════════════════ Returning ═══════════════════════════ */

    public function test_the_return_captures_the_money_and_grants_the_pass(): void
    {
        Http::fake([
            '*/v1/oauth2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 32400]),
            '*/v2/checkout/orders/ORDER-1/capture' => Http::response($this->capturedOrder(), 201),
            '*/v2/checkout/orders/ORDER-1' => Http::response([
                'id' => 'ORDER-1',
                'status' => 'APPROVED',
                'purchase_units' => [['reference_id' => 'day-pass', 'custom_id' => $this->user->id.':day-pass']],
            ]),
        ]);

        $this->actingAs($this->user)
            ->get(route('pass.return', ['token' => 'ORDER-1']))
            ->assertRedirect(route('plan.show'))
            ->assertSessionHas('checkout_done');

        $this->assertSame('Day Pass', $this->user->fresh()->currentPlan()->name);

        $transaction = Transaction::where('external_id', 'CAPTURE-1')->firstOrFail();
        $this->assertSame('completed', $transaction->status);
        $this->assertSame('one_time', $transaction->type);
        $this->assertSame(900, $transaction->amount_cents);
        $this->assertSame(61, $transaction->fee_cents);
        $this->assertSame(839, $transaction->net_cents);
        $this->assertSame($this->user->id, $transaction->user_id);
    }

    /**
     * ── THE BUG ─────────────────────────────────────────────────────────
     *
     * custom_id changed from "<id>" to "<id>:<slug>" in the method that
     * WRITES it, and this check went on comparing the whole string to a
     * bare id. They never matched again, so every genuine purchase was
     * refused as "an order belonging to somebody else".
     */
    public function test_the_return_accepts_the_buyers_own_order(): void
    {
        Http::fake([
            '*/v1/oauth2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 32400]),
            '*/v2/checkout/orders/ORDER-1' => Http::response($this->capturedOrder()),
        ]);

        $this->actingAs($this->user)
            ->get(route('pass.return', ['token' => 'ORDER-1']))
            ->assertSessionHas('checkout_done');
    }

    /** And the check it was there to perform still performs it. */
    public function test_the_return_refuses_somebody_elses_order(): void
    {
        $other = User::factory()->create();

        Http::fake([
            '*/v1/oauth2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 32400]),
            '*/v2/checkout/orders/ORDER-1' => Http::response(
                $this->capturedOrder($other->id.':day-pass'),
            ),
        ]);

        $this->actingAs($this->user)
            ->get(route('pass.return', ['token' => 'ORDER-1']))
            ->assertRedirect(route('plan.show'))
            ->assertSessionMissing('checkout_done');

        $this->assertNull(Transaction::first());
        $this->assertSame('Free', $this->user->fresh()->currentPlan()->name);
    }

    /**
     * ── THE BUG ─────────────────────────────────────────────────────────
     *
     * The buyer and the plan live on different objects: a capture has
     * custom_id and NO reference_id, a purchase unit has both. The code
     * picked one source for both facts, so it knew who had paid and not
     * what they had bought — and filed real money as unattributable.
     */
    public function test_a_capture_without_reference_id_still_finds_the_plan(): void
    {
        $capture = $this->capturedOrder()['purchase_units'][0]['payments']['captures'][0];
        unset($capture['reference_id']);

        $this->assertArrayNotHasKey('reference_id', $capture);

        $event = \App\Models\WebhookEvent::record('EV-1', [
            'gateway' => 'paypal',
            'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
            'mode' => 'sandbox',
            'verified' => true,
            'status' => \App\Models\WebhookEvent::PENDING,
            'payload' => ['resource' => $capture],
        ]);

        app(OrderEvents::class)->recordAndGrant($event, null, $capture);

        $this->assertSame('handled', $event->fresh()->status);
        $this->assertSame('Day Pass', $this->user->fresh()->currentPlan()->name);
    }
}
