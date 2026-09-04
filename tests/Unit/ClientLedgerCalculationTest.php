<?php

namespace Tests\Unit;

use App\Enums\TransactionType;
use App\Models\Client;
use App\Models\Instrument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientLedgerCalculationTest extends TestCase
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

        $balance = $client->cash_balance;

        $this->assertSame('730.00', (string) $balance->getAmount());
        $this->assertSame('USD', $balance->getCurrency()->getCurrencyCode());
    }

    public function test_holding_quantity_is_zero_when_nothing_has_ever_been_bought(): void
    {
        $client = Client::create(['name' => 'Calc Client', 'currency' => 'USD']);
        $instrument = Instrument::create(['ticker' => 'NEVERBOUGHT']);

        $quantity = $client->holdings->firstWhere('instrument_id', $instrument->id)->quantity ?? 0;

        $this->assertSame(0, $quantity);
    }
}
