<?php

namespace Tests\Feature\PayPal;

use App\Services\PayPal\PayPalClient;
use App\Services\PayPal\PayPalException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The layer that talks to PayPal.
 *
 * Every test here is a bug that reached production once. They are written
 * as the failure, not as the feature, because that is what they are for: a
 * day was spent finding these by buying things on the live site with a
 * sandbox account, one at a time, with a deployment between each.
 */
class PayPalClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.paypal.mode' => 'sandbox',
            'services.paypal.client_id' => 'test-client-id',
            'services.paypal.client_secret' => 'test-secret',
            'services.paypal.webhook_id' => 'WH-TEST',
        ]);
    }

    protected function fakeToken(array $extra = []): void
    {
        Http::fake(array_merge([
            '*/v1/oauth2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 32400]),
        ], $extra));
    }

    /**
     * ── THE BUG ─────────────────────────────────────────────────────────
     *
     * /v2/checkout/orders/{id}/capture takes no fields, so it was called
     * with an empty PHP array. PHP has one type for both of JSON's
     * containers and json_encode resolves an empty one to `[]` — a JSON
     * ARRAY. PayPal reads a body as an object and answered:
     *
     *   400 INVALID_REQUEST → (body) [MALFORMED_REQUEST_JSON]
     *
     * Every capture failed. The buyer approved, the money never moved, and
     * the page honestly said "PayPal is still confirming" — for ever.
     */
    public function test_an_empty_body_is_sent_as_an_object_not_an_array(): void
    {
        $this->fakeToken([
            '*/v2/checkout/orders/*/capture' => Http::response(['id' => 'ORDER-1', 'status' => 'COMPLETED']),
        ]);

        app(PayPalClient::class)->post('/v2/checkout/orders/ORDER-1/capture', [], requestId: 'capture-ORDER-1');

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/capture')) {
                return false;
            }

            return $request->body() === '{}';
        });
    }

    /** A body with fields is untouched by the fix above. */
    public function test_a_body_with_fields_is_sent_as_given(): void
    {
        $this->fakeToken([
            '*/v2/checkout/orders' => Http::response(['id' => 'ORDER-1']),
        ]);

        app(PayPalClient::class)->post('/v2/checkout/orders', ['intent' => 'CAPTURE']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/v2/checkout/orders')
                && $request->body() === '{"intent":"CAPTURE"}';
        });
    }

    /**
     * ── THE BUG ─────────────────────────────────────────────────────────
     *
     * PayPal answers a 400 with one sentence — "Request is not well-formed,
     * syntactically incorrect, or violates schema" — for a missing field, a
     * bad enum, a malformed amount and a URL that failed a regex. The
     * `details` array underneath names the exact field. The exception kept
     * the whole body and never showed that part, so a wrong enum value cost
     * an afternoon of guessing which of a dozen fields was wrong.
     */
    public function test_the_error_message_names_the_field_paypal_rejected(): void
    {
        $this->fakeToken([
            '*/v1/catalogs/products' => Http::response([
                'name' => 'INVALID_REQUEST',
                'message' => 'Request is not well-formed, syntactically incorrect, or violates schema.',
                'details' => [[
                    'field' => '/type',
                    'value' => 'DIGITAL_GOODS',
                    'issue' => 'INVALID_PARAMETER_VALUE',
                ]],
            ], 400),
        ]);

        try {
            app(PayPalClient::class)->post('/v1/catalogs/products', ['type' => 'DIGITAL_GOODS']);
            $this->fail('A 400 should have thrown.');
        } catch (PayPalException $e) {
            $this->assertStringContainsString('type', $e->getMessage());
            $this->assertStringContainsString('DIGITAL_GOODS', $e->getMessage());
            $this->assertStringContainsString('INVALID_PARAMETER_VALUE', $e->getMessage());
        }
    }

    /**
     * The single most important line in the client: a value nobody chose
     * must fail towards "no real money moved", never towards charging a
     * real card.
     *
     * The first version of this test asserted that 'Live' was sandbox,
     * because the class docblock said so. The code had always lowercased,
     * so it was not — and the contradiction sat on the one line in the
     * integration where being wrong means charging somebody by accident.
     *
     * The code won that argument: nobody writes "LIVE" meaning sandbox, so
     * capitalisation is not the typo worth defending against. The comment
     * was corrected and this test now pins what actually matters — that
     * everything which merely LOOKS like an instruction to go live is not
     * taken as one.
     */
    public function test_only_the_word_live_turns_on_real_money(): void
    {
        foreach (['prod', 'production', '1', 'true', 'yes', 'on', '', ' ', 'sandbox', 'livex', 'li ve'] as $configured) {
            config(['services.paypal.mode' => $configured]);

            $this->assertSame(
                PayPalClient::SANDBOX,
                (new PayPalClient)->mode(),
                "PAYPAL_MODE='{$configured}' must be treated as sandbox",
            );
        }

        // "live" in any capitalisation, and surrounding whitespace trimmed,
        // is a deliberate instruction and is honoured.
        foreach (['live', 'Live', 'LIVE', ' live '] as $configured) {
            config(['services.paypal.mode' => $configured]);

            $this->assertSame(
                PayPalClient::LIVE,
                (new PayPalClient)->mode(),
                "PAYPAL_MODE='{$configured}' must be treated as live",
            );
        }
    }

    /** Missing entirely is the same as empty, and must not mean live. */
    public function test_an_absent_mode_is_sandbox(): void
    {
        config(['services.paypal.mode' => null]);

        $this->assertSame(PayPalClient::SANDBOX, (new PayPalClient)->mode());
    }

    /**
     * Cents through round(), never (int). In binary floating point
     * 1.15 * 100 lands on 114.999… and (int) truncates it to 114. A cent
     * lost per charge is invisible until somebody reconciles a year of it.
     */
    public function test_amounts_round_instead_of_truncating(): void
    {
        $this->assertSame('1.15', PayPalClient::amount(115));
        $this->assertSame('0.29', PayPalClient::amount(29));
        $this->assertSame('14.00', PayPalClient::amount(1400));
    }

    /** Without both halves, nothing should even be attempted. */
    public function test_it_knows_when_it_is_not_configured(): void
    {
        config(['services.paypal.client_secret' => '']);

        $this->assertFalse(app(PayPalClient::class)->isConfigured());
    }
}
