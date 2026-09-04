<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesFixtures;
use Tests\TestCase;

class PortfolioEndpointsTest extends TestCase
{
    use RefreshDatabase, CreatesFixtures;

    public function test_cash_balance_endpoint_reflects_the_ledger(): void
    {
        $client = $this->createClient();

        $this->postJson("/api/clients/{$client->id}/transactions", [
            'type' => 'deposit',
            'amount' => '300.00',
        ])->assertStatus(201);

        $this->getJson("/api/clients/{$client->id}/cash-balance")
            ->assertStatus(200)
            ->assertJson(['data' => ['currency' => 'USD', 'balance' => '300.00']]);
    }

    /**
     * CLAUDE.md regression seed (Phase 4): the currency in every response
     * comes from the client's own record — tested with a non-USD currency
     * specifically because "it happened to say USD in my test" would not
     * prove anything.
     */
    public function test_cash_balance_uses_the_clients_actual_currency_not_a_hardcoded_usd(): void
    {
        $client = $this->createClient(currency: 'EUR');

        $this->postJson("/api/clients/{$client->id}/transactions", [
            'type' => 'deposit',
            'amount' => '75.00',
        ])->assertStatus(201);

        $this->getJson("/api/clients/{$client->id}/cash-balance")
            ->assertStatus(200)
            ->assertJson(['data' => ['currency' => 'EUR', 'balance' => '75.00']]);
    }

    /**
     * Same regression seed, business-rule-rejection side: the
     * insufficient_funds message must name the client's real currency.
     */
    public function test_insufficient_funds_message_uses_the_clients_actual_currency(): void
    {
        $client = $this->createClient(currency: 'EUR');

        $response = $this->postJson("/api/clients/{$client->id}/transactions", [
            'type' => 'withdrawal',
            'amount' => '50.00',
        ]);

        $response->assertStatus(422)->assertJsonPath('error_code', 'insufficient_funds');
        $this->assertStringContainsString('EUR', $response->json('message'));
        $this->assertStringNotContainsString('USD', $response->json('message'));
    }

    /**
     * Same bug class as the insufficient_funds currency fix, checked for
     * insufficient_holdings specifically since it was never explicitly
     * re-tested there. Turns out the premise doesn't transfer literally:
     * InsufficientHoldingsException's message is pure share-quantity
     * arithmetic (int requested, int held) — no Money object, no currency
     * of any kind. This test confirms that absence directly rather than
     * just asserting it in prose: the message must name neither the
     * client's real currency nor any hardcoded one.
     */
    public function test_insufficient_holdings_message_contains_no_currency_at_all(): void
    {
        $client = $this->createClient(currency: 'EUR');
        $instrument = $this->createInstrument();

        $this->postJson("/api/clients/{$client->id}/transactions", [
            'type' => 'deposit', 'amount' => '1000.00',
        ])->assertStatus(201);
        $this->postJson("/api/clients/{$client->id}/transactions", [
            'type' => 'buy', 'instrument_id' => $instrument->id, 'quantity' => 5, 'price' => '100.00',
        ])->assertStatus(201);

        $response = $this->postJson("/api/clients/{$client->id}/transactions", [
            'type' => 'sell', 'instrument_id' => $instrument->id, 'quantity' => 8, 'price' => '100.00',
        ]);

        $response->assertStatus(422)->assertJsonPath('error_code', 'insufficient_holdings');
        $message = $response->json('message');
        $this->assertStringContainsString($instrument->ticker, $message);
        $this->assertStringContainsString('requested 8', $message);
        $this->assertStringContainsString('held 5', $message);
        $this->assertStringNotContainsString('EUR', $message);
        $this->assertStringNotContainsString('USD', $message);
    }

    public function test_portfolio_endpoint_lists_holdings(): void
    {
        $client = $this->createClient();
        $instrument = $this->createInstrument();

        $this->postJson("/api/clients/{$client->id}/transactions", ['type' => 'deposit', 'amount' => '1000.00']);
        $this->postJson("/api/clients/{$client->id}/transactions", [
            'type' => 'buy', 'instrument_id' => $instrument->id, 'quantity' => 4, 'price' => '50.00',
        ]);

        $this->getJson("/api/clients/{$client->id}/portfolio")
            ->assertStatus(200)
            ->assertJson(['data' => [['instrument_id' => $instrument->id, 'quantity' => 4]]]);
    }

    public function test_transactions_endpoint_returns_paginated_history(): void
    {
        $client = $this->createClient();

        $this->postJson("/api/clients/{$client->id}/transactions", ['type' => 'deposit', 'amount' => '10.00']);
        $this->postJson("/api/clients/{$client->id}/transactions", ['type' => 'deposit', 'amount' => '20.00']);

        $this->getJson("/api/clients/{$client->id}/transactions")
            ->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2);
    }

    /**
     * CLAUDE.md regression seed (Phase 4): a nonexistent client must get a
     * clean error_code/message 404, never a debug stack trace — tested
     * with APP_DEBUG explicitly true, since that's precisely the
     * condition that produced the original bug.
     */
    public function test_nonexistent_client_returns_clean_404_even_with_debug_enabled(): void
    {
        config(['app.debug' => true]);

        $response = $this->getJson('/api/clients/999999/cash-balance');

        $response->assertStatus(404)
            ->assertExactJson([
                'error_code' => 'not_found',
                'message' => 'No query results for model [App\\Models\\Client] 999999',
            ]);
    }

    public function test_nonexistent_client_returns_clean_404_on_all_three_get_endpoints(): void
    {
        $this->getJson('/api/clients/999999/portfolio')->assertStatus(404)->assertJsonPath('error_code', 'not_found');
        $this->getJson('/api/clients/999999/transactions')->assertStatus(404)->assertJsonPath('error_code', 'not_found');
        $this->postJson('/api/clients/999999/transactions', ['type' => 'deposit', 'amount' => '1.00'])
            ->assertStatus(404)->assertJsonPath('error_code', 'not_found');
    }

    // ── Immutability ─────────────────────────────────────────────────────

    public function test_no_route_exists_to_update_a_transaction(): void
    {
        $client = $this->createClient();
        $this->postJson("/api/clients/{$client->id}/transactions", ['type' => 'deposit', 'amount' => '10.00']);
        $transaction = $client->transactions()->first();

        $this->putJson("/api/clients/{$client->id}/transactions/{$transaction->id}", ['amount' => '999.00'])
            ->assertStatus(404);
        $this->patchJson("/api/clients/{$client->id}/transactions/{$transaction->id}", ['amount' => '999.00'])
            ->assertStatus(404);
    }

    public function test_no_route_exists_to_delete_a_transaction(): void
    {
        $client = $this->createClient();
        $this->postJson("/api/clients/{$client->id}/transactions", ['type' => 'deposit', 'amount' => '10.00']);
        $transaction = $client->transactions()->first();

        $this->deleteJson("/api/clients/{$client->id}/transactions/{$transaction->id}")
            ->assertStatus(404);

        $this->assertSame(1, $client->transactions()->count());
    }
}
