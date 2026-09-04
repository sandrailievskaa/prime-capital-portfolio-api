<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Instrument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortfolioZeroHoldingsTest extends TestCase
{
    use RefreshDatabase;

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

        $holdings = $client->holdings;

        $this->assertFalse(
            $holdings->contains('instrument_id', $instrument->id),
            'Instrument sold down to exactly zero must be absent from holdings(), not present with quantity 0.'
        );
    }
}
