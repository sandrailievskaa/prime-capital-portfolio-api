<?php

namespace Tests\Feature;

use App\Services\PortfolioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesFixtures;
use Tests\TestCase;

class MultiClientIsolationTest extends TestCase
{
    use RefreshDatabase, CreatesFixtures;

    /**
     * Highest-risk case #5: one client's activity must never bleed into
     * another's balance or holdings. Both clients transact on the same
     * instrument deliberately, to catch an aggregate query that forgets
     * to filter by client_id.
     */
    public function test_two_clients_transactions_never_affect_each_others_balance_or_holdings(): void
    {
        $clientA = $this->createClient();
        $clientB = $this->createClient();
        $instrument = $this->createInstrument();

        $this->postJson("/api/clients/{$clientA->id}/transactions", ['type' => 'deposit', 'amount' => '1000.00'])
            ->assertStatus(201);
        $this->postJson("/api/clients/{$clientB->id}/transactions", ['type' => 'deposit', 'amount' => '500.00'])
            ->assertStatus(201);

        $this->postJson("/api/clients/{$clientA->id}/transactions", [
            'type' => 'buy', 'instrument_id' => $instrument->id, 'quantity' => 10, 'price' => '50.00',
        ])->assertStatus(201);
        $this->postJson("/api/clients/{$clientB->id}/transactions", [
            'type' => 'buy', 'instrument_id' => $instrument->id, 'quantity' => 3, 'price' => '50.00',
        ])->assertStatus(201);

        $portfolio = app(PortfolioService::class);

        $this->assertSame('500.00', (string) $portfolio->cashBalance($clientA)->getAmount());
        $this->assertSame('350.00', (string) $portfolio->cashBalance($clientB)->getAmount());
        $this->assertSame(10, $portfolio->holdingQuantity($clientA, $instrument));
        $this->assertSame(3, $portfolio->holdingQuantity($clientB, $instrument));

        // A withdrawal that would be valid for A but not B must be judged
        // against B's own balance, not A's.
        $this->postJson("/api/clients/{$clientB->id}/transactions", [
            'type' => 'withdrawal', 'amount' => '400.00',
        ])->assertStatus(422)->assertJsonPath('error_code', 'insufficient_funds');

        // And a sell valid for A's holding count must not be allowed
        // against B's much smaller holding of the same instrument.
        $this->postJson("/api/clients/{$clientB->id}/transactions", [
            'type' => 'sell', 'instrument_id' => $instrument->id, 'quantity' => 8, 'price' => '50.00',
        ])->assertStatus(422)->assertJsonPath('error_code', 'insufficient_holdings');

        $this->assertSame(2, $clientA->transactions()->count());
        $this->assertSame(2, $clientB->transactions()->count());
    }

    public function test_transaction_history_endpoint_only_returns_the_requested_clients_rows(): void
    {
        $clientA = $this->createClient();
        $clientB = $this->createClient();

        $this->postJson("/api/clients/{$clientA->id}/transactions", ['type' => 'deposit', 'amount' => '10.00']);
        $this->postJson("/api/clients/{$clientA->id}/transactions", ['type' => 'deposit', 'amount' => '20.00']);
        $this->postJson("/api/clients/{$clientB->id}/transactions", ['type' => 'deposit', 'amount' => '999.00']);

        $response = $this->getJson("/api/clients/{$clientA->id}/transactions")->assertStatus(200);

        $response->assertJsonCount(2, 'data');
        foreach ($response->json('data') as $row) {
            $this->assertSame($clientA->id, $row['client_id']);
        }
    }
}
