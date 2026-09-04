<?php

namespace Tests\Feature;

use App\Services\PortfolioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesFixtures;
use Tests\TestCase;

class AnaScenarioTest extends TestCase
{
    use RefreshDatabase, CreatesFixtures;

    /**
     * CLAUDE.md regression seed (Phase 5): the exact client-provided
     * scenario, end to end, as a single ordered integration test. Matches
     * the manual verification run from Phase 5/6 (including buying with a
     * second instrument at the exact-remaining-cash boundary, and the
     * redeposit before continuing, both needed to make the stated
     * balance/holdings numbers land exactly as given).
     */
    public function test_the_exact_ana_scenario_end_to_end(): void
    {
        $client = $this->createClient();
        $aapl = $this->createInstrument('AAPL');
        $msft = $this->createInstrument('MSFT');
        $portfolio = app(PortfolioService::class);

        // 1. deposit 1000 -> balance 1000.00
        $this->postJson("/api/clients/{$client->id}/transactions", [
            'type' => 'deposit', 'amount' => '1000.00',
        ])->assertStatus(201);
        $this->assertSame('1000.00', (string) $portfolio->cashBalance($client)->getAmount());

        // 2. buy 5 AAPL @ 100.00 -> balance 500.00, holdings AAPL 5
        $this->postJson("/api/clients/{$client->id}/transactions", [
            'type' => 'buy', 'instrument_id' => $aapl->id, 'quantity' => 5, 'price' => '100.00',
        ])->assertStatus(201);
        $this->assertSame('500.00', (string) $portfolio->cashBalance($client)->getAmount());
        $this->assertSame(5, $portfolio->holdingQuantity($client, $aapl));

        // 3. attempt buy costing 700 -> rejected, balance still 500.00
        $this->postJson("/api/clients/{$client->id}/transactions", [
            'type' => 'buy', 'instrument_id' => $aapl->id, 'quantity' => 7, 'price' => '100.00',
        ])->assertStatus(422)->assertJsonPath('error_code', 'insufficient_funds');
        $this->assertSame('500.00', (string) $portfolio->cashBalance($client)->getAmount());

        // 4. attempt sell 8 AAPL -> rejected, holdings still 5
        $this->postJson("/api/clients/{$client->id}/transactions", [
            'type' => 'sell', 'instrument_id' => $aapl->id, 'quantity' => 8, 'price' => '100.00',
        ])->assertStatus(422)->assertJsonPath('error_code', 'insufficient_holdings');
        $this->assertSame(5, $portfolio->holdingQuantity($client, $aapl));

        // 5. buy costing exactly 500 (a different instrument, MSFT, so it
        // doesn't disturb the AAPL count) -> succeeds, balance 0.00
        $this->postJson("/api/clients/{$client->id}/transactions", [
            'type' => 'buy', 'instrument_id' => $msft->id, 'quantity' => 5, 'price' => '100.00',
        ])->assertStatus(201);
        $this->assertSame('0.00', (string) $portfolio->cashBalance($client)->getAmount());
        $this->assertSame(5, $portfolio->holdingQuantity($client, $aapl));

        // redeposit 500 before continuing
        $this->postJson("/api/clients/{$client->id}/transactions", [
            'type' => 'deposit', 'amount' => '500.00',
        ])->assertStatus(201);

        // 6. sell 3 AAPL @ 120.00 -> balance 860.00, holdings AAPL 2
        $this->postJson("/api/clients/{$client->id}/transactions", [
            'type' => 'sell', 'instrument_id' => $aapl->id, 'quantity' => 3, 'price' => '120.00',
        ])->assertStatus(201);
        $this->assertSame('860.00', (string) $portfolio->cashBalance($client)->getAmount());
        $this->assertSame(2, $portfolio->holdingQuantity($client, $aapl));

        // 7. sell the remaining 2 AAPL -> AAPL absent entirely, not
        // quantity 0
        $this->postJson("/api/clients/{$client->id}/transactions", [
            'type' => 'sell', 'instrument_id' => $aapl->id, 'quantity' => 2, 'price' => '120.00',
        ])->assertStatus(201);

        $holdings = $this->getJson("/api/clients/{$client->id}/portfolio")->assertStatus(200)->json('data');
        $this->assertFalse(
            collect($holdings)->contains('instrument_id', $aapl->id),
            'AAPL must be entirely absent from the portfolio response after being sold to zero.'
        );
    }
}
