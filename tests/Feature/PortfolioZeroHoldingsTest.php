<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Instrument;
use App\Services\PortfolioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortfolioZeroHoldingsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Regression test for a real bug found during Phase 5 manual
     * verification: PortfolioService::holdings() used
     * havingRaw('quantity > 0') referencing a SELECT alias that collided
     * with the real transactions.quantity column — SQLite silently let
     * net-zero rows through instead of excluding them (rule 10). Asserts
     * absence, not quantity === 0 — that distinction is exactly what the
     * bug broke, so a weaker assertion would not have caught it.
     */
    public function test_instrument_sold_down_to_zero_is_absent_from_holdings_not_present_with_zero_quantity(): void
    {
        $client = Client::create(['name' => 'Zero Holdings Client', 'currency' => 'USD']);
        $instrument = Instrument::create(['ticker' => 'ZEROTEST']);

        $this->postJson("/api/clients/{$client->id}/transactions", [
            'type' => 'deposit',
            'amount' => '1000.00',
        ])->assertStatus(201);

        $this->postJson("/api/clients/{$client->id}/transactions", [
            'type' => 'buy',
            'instrument_id' => $instrument->id,
            'quantity' => 5,
            'price' => '100.00',
        ])->assertStatus(201);

        $this->postJson("/api/clients/{$client->id}/transactions", [
            'type' => 'sell',
            'instrument_id' => $instrument->id,
            'quantity' => 5,
            'price' => '100.00',
        ])->assertStatus(201);

        $holdings = app(PortfolioService::class)->holdings($client);

        $this->assertFalse(
            $holdings->contains('instrument_id', $instrument->id),
            'Instrument sold down to exactly zero must be absent from holdings(), not present with quantity 0.'
        );
    }
}
