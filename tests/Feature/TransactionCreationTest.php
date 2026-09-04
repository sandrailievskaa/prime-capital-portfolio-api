<?php

namespace Tests\Feature;

use App\Services\PortfolioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesFixtures;
use Tests\TestCase;

class TransactionCreationTest extends TestCase
{
    use RefreshDatabase, CreatesFixtures;

    // ── Valid cases ─────────────────────────────────────────────────────

    public function test_deposit_succeeds_and_updates_balance(): void
    {
        $client = $this->createClient();

        $this->postJson("/api/clients/{$client->id}/transactions", [
            'type' => 'deposit',
            'amount' => '250.00',
        ])->assertStatus(201)
            ->assertJsonPath('data.type', 'deposit')
            ->assertJsonPath('data.amount', '250.00');

        $this->assertBalance($client, '250.00');
    }

    /**
     * Regression test (Phase 8, found via adversarial QA):
     * Transaction::create() returns the in-memory instance built from the
     * attributes passed to it — it does NOT re-fetch DB-computed column
     * defaults after INSERT. Every TransactionService write method now
     * passes transaction_fee explicitly for exactly this reason; before
     * that fix, every successful response showed transaction_fee: null
     * even though the actual DB row correctly held 0.00. This test checks
     * both sides: the API response and a fresh read of the same row from
     * the database.
     */
    public function test_transaction_fee_shows_zero_not_null_in_the_response_and_matches_the_database(): void
    {
        $client = $this->createClient();

        $response = $this->postJson("/api/clients/{$client->id}/transactions", [
            'type' => 'deposit',
            'amount' => '10.00',
        ])->assertStatus(201);

        $response->assertJsonPath('data.transaction_fee', '0.00');

        $fresh = \App\Models\Transaction::find($response->json('data.id'));
        $this->assertSame('0.00', (string) $fresh->transaction_fee);
    }

    public function test_withdrawal_within_balance_succeeds(): void
    {
        $client = $this->createClient();
        $this->deposit($client, '500.00');

        $this->postJson("/api/clients/{$client->id}/transactions", [
            'type' => 'withdrawal',
            'amount' => '200.00',
        ])->assertStatus(201);

        $this->assertBalance($client, '300.00');
    }

    /**
     * CLAUDE.md regression seed (Phase 4): the boundary is a success, not
     * a rejection.
     */
    public function test_withdrawal_exactly_equal_to_balance_succeeds_landing_on_zero(): void
    {
        $client = $this->createClient();
        $this->deposit($client, '500.00');

        $this->postJson("/api/clients/{$client->id}/transactions", [
            'type' => 'withdrawal',
            'amount' => '500.00',
        ])->assertStatus(201);

        $this->assertBalance($client, '0.00');
    }

    public function test_buy_within_cash_succeeds(): void
    {
        $client = $this->createClient();
        $instrument = $this->createInstrument();
        $this->deposit($client, '1000.00');

        $this->postJson("/api/clients/{$client->id}/transactions", [
            'type' => 'buy',
            'instrument_id' => $instrument->id,
            'quantity' => 3,
            'price' => '100.00',
        ])->assertStatus(201);

        $this->assertBalance($client, '700.00');
        $this->assertHolding($client, $instrument->id, 3);
    }

    /**
     * Same boundary principle as the withdrawal regression seed, applied
     * to buy: spending exactly the full balance is a success.
     */
    public function test_buy_costing_exactly_available_cash_succeeds_landing_on_zero(): void
    {
        $client = $this->createClient();
        $instrument = $this->createInstrument();
        $this->deposit($client, '500.00');

        $this->postJson("/api/clients/{$client->id}/transactions", [
            'type' => 'buy',
            'instrument_id' => $instrument->id,
            'quantity' => 5,
            'price' => '100.00',
        ])->assertStatus(201);

        $this->assertBalance($client, '0.00');
        $this->assertHolding($client, $instrument->id, 5);
    }

    public function test_sell_within_holdings_succeeds(): void
    {
        $client = $this->createClient();
        $instrument = $this->createInstrument();
        $this->deposit($client, '1000.00');
        $this->buy($client, $instrument, 10, '50.00');

        $this->postJson("/api/clients/{$client->id}/transactions", [
            'type' => 'sell',
            'instrument_id' => $instrument->id,
            'quantity' => 4,
            'price' => '60.00',
        ])->assertStatus(201);

        // 1000 - 500 (buy) + 240 (sell) = 740
        $this->assertBalance($client, '740.00');
        $this->assertHolding($client, $instrument->id, 6);
    }

    /**
     * CLAUDE.md regression seed (Phase 5, HAVING-alias bug): selling the
     * full holding must succeed and leave the instrument absent, not
     * present with quantity 0. This test only checks the write succeeds
     * and the total row disappears from a fresh holdings() computation —
     * the dedicated absence-in-response assertion already lives in
     * PortfolioZeroHoldingsTest and is not duplicated here.
     */
    public function test_selling_exactly_the_full_holding_succeeds(): void
    {
        $client = $this->createClient();
        $instrument = $this->createInstrument();
        $this->deposit($client, '1000.00');
        $this->buy($client, $instrument, 5, '100.00');

        $this->postJson("/api/clients/{$client->id}/transactions", [
            'type' => 'sell',
            'instrument_id' => $instrument->id,
            'quantity' => 5,
            'price' => '100.00',
        ])->assertStatus(201);

        $holdings = app(PortfolioService::class)->holdings($client);
        $this->assertFalse($holdings->contains('instrument_id', $instrument->id));
    }

    public function test_multiple_buys_and_sells_accumulate_correctly(): void
    {
        $client = $this->createClient();
        $instrument = $this->createInstrument();
        $this->deposit($client, '10000.00');

        $this->buy($client, $instrument, 10, '50.00');   // -500, holding 10
        $this->buy($client, $instrument, 5, '55.00');    // -275, holding 15
        $this->sell($client, $instrument, 8, '60.00');   // +480, holding 7

        // 10000 - 500 - 275 + 480 = 9705
        $this->assertBalance($client, '9705.00');
        $this->assertHolding($client, $instrument->id, 7);
    }

    public function test_holdings_are_kept_separate_per_instrument(): void
    {
        $client = $this->createClient();
        $aapl = $this->createInstrument('AAPL');
        $msft = $this->createInstrument('MSFT');
        $this->deposit($client, '10000.00');

        $this->buy($client, $aapl, 10, '100.00');
        $this->buy($client, $msft, 4, '200.00');
        $this->sell($client, $aapl, 3, '110.00');

        $this->assertHolding($client, $aapl->id, 7);
        $this->assertHolding($client, $msft->id, 4);
    }

    public function test_different_buy_and_sell_prices_are_reflected_in_balance(): void
    {
        $client = $this->createClient();
        $instrument = $this->createInstrument();
        $this->deposit($client, '1000.00');

        $this->buy($client, $instrument, 5, '100.00');  // -500
        $this->sell($client, $instrument, 5, '150.00'); // +750

        // 1000 - 500 + 750 = 1250
        $this->assertBalance($client, '1250.00');
    }

    // ── Invalid: field-presence rules per type ──────────────────────────

    public function test_amount_is_rejected_on_buy(): void
    {
        $client = $this->createClient();
        $instrument = $this->createInstrument();

        $this->postJson("/api/clients/{$client->id}/transactions", [
            'type' => 'buy',
            'instrument_id' => $instrument->id,
            'quantity' => 1,
            'price' => '10.00',
            'amount' => '10.00',
        ])->assertStatus(422)
            ->assertJsonPath('error_code', 'validation_failed')
            ->assertJsonValidationErrors('amount');
    }

    public function test_amount_is_rejected_on_sell(): void
    {
        $client = $this->createClient();
        $instrument = $this->createInstrument();

        $this->postJson("/api/clients/{$client->id}/transactions", [
            'type' => 'sell',
            'instrument_id' => $instrument->id,
            'quantity' => 1,
            'price' => '10.00',
            'amount' => '10.00',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('amount');
    }

    public function test_price_and_quantity_are_rejected_on_deposit(): void
    {
        $client = $this->createClient();

        $this->postJson("/api/clients/{$client->id}/transactions", [
            'type' => 'deposit',
            'amount' => '10.00',
            'price' => '10.00',
            'quantity' => 1,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['price', 'quantity']);
    }

    public function test_price_and_quantity_are_rejected_on_withdrawal(): void
    {
        $client = $this->createClient();

        $this->postJson("/api/clients/{$client->id}/transactions", [
            'type' => 'withdrawal',
            'amount' => '10.00',
            'price' => '10.00',
            'quantity' => 1,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['price', 'quantity']);
    }

    /**
     * Adversarial QA (Phase 8): transaction_fee is not in
     * CreateTransactionRequest::rules(), so it must be rejected by the
     * same unknown-field mechanism as any other unrecognized key — never
     * silently accepted or silently ignored.
     */
    public function test_transaction_fee_is_rejected_as_an_unknown_field_on_deposit(): void
    {
        $client = $this->createClient();

        $this->postJson("/api/clients/{$client->id}/transactions", [
            'type' => 'deposit',
            'amount' => '100.00',
            'transaction_fee' => '999.00',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('transaction_fee');
    }

    public function test_transaction_fee_is_rejected_as_an_unknown_field_on_buy(): void
    {
        $client = $this->createClient();
        $instrument = $this->createInstrument();

        $this->postJson("/api/clients/{$client->id}/transactions", [
            'type' => 'buy',
            'instrument_id' => $instrument->id,
            'quantity' => 1,
            'price' => '10.00',
            'transaction_fee' => '999.00',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('transaction_fee');
    }

    // ── Atomicity: rejections leave zero trace ──────────────────────────

    /**
     * Highest-risk case #4: a rejected write must not move the needle on
     * anything — no row, no balance change, no count change.
     */
    public function test_rejected_withdrawal_leaves_zero_trace(): void
    {
        $client = $this->createClient();
        $this->deposit($client, '100.00');

        $this->postJson("/api/clients/{$client->id}/transactions", [
            'type' => 'withdrawal',
            'amount' => '150.00',
        ])->assertStatus(422)->assertJsonPath('error_code', 'insufficient_funds');

        $this->assertBalance($client, '100.00');
        $this->assertSame(1, $client->transactions()->count());
    }

    public function test_rejected_buy_leaves_zero_trace(): void
    {
        $client = $this->createClient();
        $instrument = $this->createInstrument();
        $this->deposit($client, '100.00');

        $this->postJson("/api/clients/{$client->id}/transactions", [
            'type' => 'buy',
            'instrument_id' => $instrument->id,
            'quantity' => 5,
            'price' => '100.00',
        ])->assertStatus(422)->assertJsonPath('error_code', 'insufficient_funds');

        $this->assertBalance($client, '100.00');
        $this->assertSame(1, $client->transactions()->count());
    }

    public function test_rejected_sell_leaves_zero_trace(): void
    {
        $client = $this->createClient();
        $instrument = $this->createInstrument();
        $this->deposit($client, '1000.00');
        $this->buy($client, $instrument, 5, '100.00');

        $this->postJson("/api/clients/{$client->id}/transactions", [
            'type' => 'sell',
            'instrument_id' => $instrument->id,
            'quantity' => 10,
            'price' => '100.00',
        ])->assertStatus(422)->assertJsonPath('error_code', 'insufficient_holdings');

        $this->assertBalance($client, '500.00');
        $this->assertHolding($client, $instrument->id, 5);
        $this->assertSame(2, $client->transactions()->count());
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function deposit($client, string $amount): void
    {
        $this->postJson("/api/clients/{$client->id}/transactions", [
            'type' => 'deposit',
            'amount' => $amount,
        ])->assertStatus(201);
    }

    private function buy($client, $instrument, int $quantity, string $price): void
    {
        $this->postJson("/api/clients/{$client->id}/transactions", [
            'type' => 'buy',
            'instrument_id' => $instrument->id,
            'quantity' => $quantity,
            'price' => $price,
        ])->assertStatus(201);
    }

    private function sell($client, $instrument, int $quantity, string $price): void
    {
        $this->postJson("/api/clients/{$client->id}/transactions", [
            'type' => 'sell',
            'instrument_id' => $instrument->id,
            'quantity' => $quantity,
            'price' => $price,
        ])->assertStatus(201);
    }

    private function assertBalance($client, string $expected): void
    {
        $this->assertSame(
            $expected,
            (string) app(PortfolioService::class)->cashBalance($client)->getAmount()
        );
    }

    private function assertHolding($client, int $instrumentId, int $expectedQuantity): void
    {
        $this->assertSame(
            $expectedQuantity,
            app(PortfolioService::class)->holdingQuantity($client, \App\Models\Instrument::find($instrumentId))
        );
    }
}
