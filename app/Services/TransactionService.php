<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Exceptions\InsufficientFundsException;
use App\Exceptions\InsufficientHoldingsException;
use App\Models\Client;
use App\Models\Instrument;
use App\Models\Transaction;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Illuminate\Support\Facades\DB;

/**
 * Four separately-readable methods, no shared pipeline across them (rule
 * 16): deposit/withdraw insert `amount`; buy/sell insert
 * `instrument_id`/`quantity`/`price` and validate against a fundamentally
 * different check (Money comparison vs. integer comparison). Extracting a
 * generic "lock, check, insert" template would hide that difference behind
 * a callback instead of leaving it readable inline.
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
                // Explicit, not relied on as the column's DB-level default:
                // Transaction::create() returns the in-memory instance built
                // from what's passed in — it does NOT re-fetch DB-computed
                // defaults after INSERT. Omitting this left every API
                // response showing transaction_fee: null instead of the
                // true "0.00" (found via adversarial QA, Phase 8).
                'transaction_fee' => 0,
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
                'transaction_fee' => 0,
            ]);
        });
    }

    public function buy(Client $client, Instrument $instrument, int $quantity, string $price): Transaction
    {
        return DB::transaction(function () use ($client, $instrument, $quantity, $price) {
            $locked = Client::lockForUpdate()->findOrFail($client->id);

            // total = quantity × price, full stop — transaction_fee (rule
            // 12) has no effect here and is never referenced in this
            // calculation. RoundingMode::Unnecessary is brick/money's
            // default for multipliedBy(), but stated explicitly here
            // rather than relied on implicitly: rule 15 guarantees this
            // multiplication never needs rounding (quantity is always a
            // whole integer), so if it ever did, that's a bug — this
            // throws instead of silently rounding, and stays true even if
            // the library's own default ever changes.
            $total = Money::of($price, $locked->currency)->multipliedBy($quantity, RoundingMode::Unnecessary);
            $balance = $this->portfolio->cashBalance($locked);

            // rule 8: cash must never go negative. Spending exactly the
            // full balance is allowed — only a total strictly greater than
            // available cash is rejected, same boundary rule as withdraw().
            if ($total->isGreaterThan($balance)) {
                throw new InsufficientFundsException($locked, $total, $balance);
            }

            return Transaction::create([
                'client_id' => $locked->id,
                'type' => TransactionType::Buy,
                'instrument_id' => $instrument->id,
                'quantity' => $quantity,
                'price' => $price,
                'transaction_fee' => 0,
            ]);
        });
    }

    public function sell(Client $client, Instrument $instrument, int $quantity, string $price): Transaction
    {
        return DB::transaction(function () use ($client, $instrument, $quantity, $price) {
            $locked = Client::lockForUpdate()->findOrFail($client->id);

            $held = $this->portfolio->holdingQuantity($locked, $instrument);

            // rule 9: never sell more than currently held. Selling exactly
            // the full held quantity (down to zero) is explicitly allowed
            // — only a request strictly greater than held is rejected.
            if ($quantity > $held) {
                throw new InsufficientHoldingsException($locked, $instrument, $quantity, $held);
            }

            return Transaction::create([
                'client_id' => $locked->id,
                'type' => TransactionType::Sell,
                'instrument_id' => $instrument->id,
                'quantity' => $quantity,
                'price' => $price,
                'transaction_fee' => 0,
            ]);
        });
    }
}
