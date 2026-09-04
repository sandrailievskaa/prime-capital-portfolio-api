<?php

namespace Tests\Concerns;

use App\Models\Client;
use App\Models\Instrument;

/**
 * Trivial object-construction helpers only — no business logic hidden
 * here. Deposit/buy/sell calls stay as literal HTTP requests inline in
 * each test, not wrapped, so the test body itself stays the readable
 * record of what's being exercised.
 */
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
}
