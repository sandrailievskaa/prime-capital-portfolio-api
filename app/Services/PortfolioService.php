<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\Client;
use Brick\Money\Money;

class PortfolioService
{
    /**
     * Cash balance derived live from the ledger (rule 7) — no cached
     * column anywhere. Summed in PHP via brick/money's arbitrary-precision
     * arithmetic rather than a SQL SUM(): SQLite's NUMERIC-affinity columns
     * are computed internally as IEEE-754 doubles, which is not a
     * precision guarantee at the edges of decimal(15,2)'s range. This
     * keeps the entire calculation path free of floats end to end.
     */
    public function cashBalance(Client $client): Money
    {
        $balance = Money::zero($client->currency);

        foreach ($client->transactions()->get(['type', 'amount', 'quantity', 'price']) as $transaction) {
            $balance = match ($transaction->type) {
                TransactionType::Deposit => $balance->plus(Money::of($transaction->amount, $client->currency)),
                TransactionType::Withdrawal => $balance->minus(Money::of($transaction->amount, $client->currency)),
                TransactionType::Sell => $balance->plus(
                    Money::of($transaction->price, $client->currency)->multipliedBy($transaction->quantity)
                ),
                TransactionType::Buy => $balance->minus(
                    Money::of($transaction->price, $client->currency)->multipliedBy($transaction->quantity)
                ),
            };
        }

        return $balance;
    }
}
