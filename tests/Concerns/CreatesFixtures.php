<?php

namespace Tests\Concerns;

use App\Models\Client;
use App\Models\Instrument;

trait CreatesFixtures
{
    protected function createClient(string $currency = 'USD'): Client
    {
        return Client::create(['name' => 'Test Client', 'currency' => $currency]);
    }

    protected function createInstrument(string $ticker = 'AAPL'): Instrument
    {
        return Instrument::create(['ticker' => $ticker]);
    }

    protected function holdingQuantity(Client $client, Instrument $instrument): int
    {
        return $client->holdings->firstWhere('instrument_id', $instrument->id)->quantity ?? 0;
    }
}
