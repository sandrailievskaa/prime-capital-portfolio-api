<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Exceptions\InsufficientFundsException;
use App\Models\Client;
use App\Models\Transaction;
use Brick\Money\Money;
use Illuminate\Support\Facades\DB;

/**
 * Deposit/withdraw only this phase — buy/sell come next phase (CLAUDE.md
 * rule 16: four separately-readable methods, not a shared abstraction
 * forced across them).
 */
class TransactionService
{
    public function __construct(
        private readonly PortfolioService $portfolio,
    ) {}

    public function deposit(Client $client, string $amount): Transaction
    {
        return DB::transaction(function () use ($client, $amount) {
            // ADR-004: lock first, even though it's a no-op on SQLite —
            // the transaction boundary and lock ordering are what matter,
            // and this is the habitual shape regardless of DB engine.
            Client::lockForUpdate()->findOrFail($client->id);

            return Transaction::create([
                'client_id' => $client->id,
                'type' => TransactionType::Deposit,
                'amount' => $amount,
            ]);
        });
    }

    public function withdraw(Client $client, string $amount): Transaction
    {
        return DB::transaction(function () use ($client, $amount) {
            $locked = Client::lockForUpdate()->findOrFail($client->id);

            $balance = $this->portfolio->cashBalance($locked);
            $requested = Money::of($amount, $locked->currency);

            // rule 8: cash must never go negative. Equal to the full
            // balance is explicitly allowed (rule 9's "down to zero"
            // principle applies the same way to cash) — only strictly
            // greater than the available balance is rejected.
            if ($requested->isGreaterThan($balance)) {
                throw new InsufficientFundsException($locked, $requested, $balance);
            }

            return Transaction::create([
                'client_id' => $locked->id,
                'type' => TransactionType::Withdrawal,
                'amount' => $amount,
            ]);
        });
    }
}
