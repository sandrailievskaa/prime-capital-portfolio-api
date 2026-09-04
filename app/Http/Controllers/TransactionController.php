<?php

namespace App\Http\Controllers;

use App\Enums\TransactionType;
use App\Exceptions\InsufficientFundsException;
use App\Exceptions\InsufficientHoldingsException;
use App\Http\Requests\StoreTransactionRequest;
use App\Http\Resources\TransactionResource;
use App\Models\Client;
use App\Models\Instrument;
use App\Models\Transaction;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class TransactionController extends Controller
{
    public function index(Client $client): AnonymousResourceCollection
    {
        return TransactionResource::collection(
            $client->transactions()->with('instrument')->orderBy('id')->paginate()
        );
    }

    public function store(StoreTransactionRequest $request, Client $client): JsonResponse
    {
        $type = TransactionType::from($request->validated('type'));

        $transaction = match ($type) {
            TransactionType::Deposit => $this->deposit($client, $request->validated('amount')),
            TransactionType::Withdrawal => $this->withdraw($client, $request->validated('amount')),
            TransactionType::Buy => $this->buy(
                $client,
                Instrument::findOrFail($request->validated('instrument_id')),
                $request->validated('quantity'),
                $request->validated('price'),
            ),
            TransactionType::Sell => $this->sell(
                $client,
                Instrument::findOrFail($request->validated('instrument_id')),
                $request->validated('quantity'),
                $request->validated('price'),
            ),
        };

        return TransactionResource::make($transaction)
            ->response()
            ->setStatusCode(201);
    }

    private function deposit(Client $client, string $amount): Transaction
    {
        return DB::transaction(function () use ($client, $amount) {
            Client::lockForUpdate()->findOrFail($client->id);

            return Transaction::create([
                'client_id' => $client->id,
                'type' => TransactionType::Deposit,
                'amount' => $amount,
                'transaction_fee' => 0,
            ]);
        });
    }

    private function withdraw(Client $client, string $amount): Transaction
    {
        return DB::transaction(function () use ($client, $amount) {
            $locked = Client::lockForUpdate()->findOrFail($client->id);

            $balance = $locked->cash_balance;
            $requested = Money::of($amount, $locked->currency);

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

    private function buy(Client $client, Instrument $instrument, int $quantity, string $price): Transaction
    {
        return DB::transaction(function () use ($client, $instrument, $quantity, $price) {
            $locked = Client::lockForUpdate()->findOrFail($client->id);

            $total = Money::of($price, $locked->currency)->multipliedBy($quantity, RoundingMode::Unnecessary);
            $balance = $locked->cash_balance;

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

    private function sell(Client $client, Instrument $instrument, int $quantity, string $price): Transaction
    {
        return DB::transaction(function () use ($client, $instrument, $quantity, $price) {
            $locked = Client::lockForUpdate()->findOrFail($client->id);

            $held = $locked->holdings->firstWhere('instrument_id', $instrument->id)->quantity ?? 0;

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
