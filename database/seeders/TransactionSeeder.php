<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Instrument;
use Illuminate\Database\Seeder;

class TransactionSeeder extends Seeder
{
    /**
     * Ana's ledger mirrors the client's brief example, stopped at the
     * checkpoint where balance = 860.00 and holdings = AAPL 2 (before the
     * brief's final "sell the remaining 2 AAPL" step, which would empty
     * that holding back out).
     */
    public function run(): void
    {
        $ana = Client::where('name', 'Ana')->firstOrFail();
        $aapl = Instrument::where('ticker', 'AAPL')->firstOrFail();
        $msft = Instrument::where('ticker', 'MSFT')->firstOrFail();

        $ana->transactions()->create(['type' => 'deposit', 'amount' => '1000.00', 'transaction_fee' => 0]);
        $ana->transactions()->create(['type' => 'buy', 'instrument_id' => $aapl->id, 'quantity' => 5, 'price' => '100.00', 'transaction_fee' => 0]);
        $ana->transactions()->create(['type' => 'buy', 'instrument_id' => $msft->id, 'quantity' => 5, 'price' => '100.00', 'transaction_fee' => 0]);
        $ana->transactions()->create(['type' => 'deposit', 'amount' => '500.00', 'transaction_fee' => 0]);
        $ana->transactions()->create(['type' => 'sell', 'instrument_id' => $aapl->id, 'quantity' => 3, 'price' => '120.00', 'transaction_fee' => 0]);

        $marko = Client::where('name', 'Marko')->firstOrFail();
        $tsla = Instrument::where('ticker', 'TSLA')->firstOrFail();

        $marko->transactions()->create(['type' => 'deposit', 'amount' => '2000.00', 'transaction_fee' => 0]);
        $marko->transactions()->create(['type' => 'buy', 'instrument_id' => $tsla->id, 'quantity' => 10, 'price' => '180.00', 'transaction_fee' => 0]);

        $elena = Client::where('name', 'Elena')->firstOrFail();

        $elena->transactions()->create(['type' => 'deposit', 'amount' => '300.00', 'transaction_fee' => 0]);
    }
}
