<?php

namespace Tests\Unit;

use App\Enums\TransactionType;
use App\Models\Client;
use App\Models\Instrument;
use App\Services\PortfolioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit-level in the sense the framework intends here: no HTTP, direct
 * calls against hand-built ledger rows, to make the pure arithmetic
 * intent legible on its own — RefreshDatabase is still needed since the
 * calculation reads real rows via Eloquent, but nothing here goes through
 * a route or controller.
 */
class PortfolioServiceCalculationTest extends TestCase
{
    use RefreshDatabase;

    public function test_cash_balance_sums_all_four_transaction_types_correctly(): void
    {
        $client = Client::create(['name' => 'Calc Client', 'currency' => 'USD']);
        $instrument = Instrument::create(['ticker' => 'CALC']);

        $client->transactions()->create(['type' => TransactionType::Deposit, 'amount' => '1000.00']);
        $client->transactions()->create(['type' => TransactionType::Withdrawal, 'amount' => '200.00']);
        $client->transactions()->create([
            'type' => TransactionType::Buy, 'instrument_id' => $instrument->id, 'quantity' => 3, 'price' => '50.00',
        ]);
        $client->transactions()->create([
            'type' => TransactionType::Sell, 'instrument_id' => $instrument->id, 'quantity' => 1, 'price' => '80.00',
        ]);

        // 1000 - 200 - 150 (3*50) + 80 (1*80) = 730
        $balance = app(PortfolioService::class)->cashBalance($client);

        $this->assertSame('730.00', (string) $balance->getAmount());
        $this->assertSame('USD', $balance->getCurrency()->getCurrencyCode());
    }

    public function test_holding_quantity_is_zero_when_nothing_has_ever_been_bought(): void
    {
        $client = Client::create(['name' => 'Calc Client', 'currency' => 'USD']);
        $instrument = Instrument::create(['ticker' => 'NEVERBOUGHT']);

        $quantity = app(PortfolioService::class)->holdingQuantity($client, $instrument);

        $this->assertSame(0, $quantity);
    }
}
