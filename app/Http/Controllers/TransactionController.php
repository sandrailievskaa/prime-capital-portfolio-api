<?php

namespace App\Http\Controllers;

use App\Enums\TransactionType;
use App\Http\Requests\CreateTransactionRequest;
use App\Http\Resources\TransactionResource;
use App\Models\Client;
use App\Models\Instrument;
use App\Services\TransactionService;
use Illuminate\Http\JsonResponse;

class TransactionController extends Controller
{
    public function __construct(
        private readonly TransactionService $transactions,
    ) {}

    public function store(CreateTransactionRequest $request, Client $client): JsonResponse
    {
        $type = TransactionType::from($request->validated('type'));

        $transaction = match ($type) {
            TransactionType::Deposit => $this->transactions->deposit($client, $request->validated('amount')),
            TransactionType::Withdrawal => $this->transactions->withdraw($client, $request->validated('amount')),
            TransactionType::Buy => $this->transactions->buy(
                $client,
                Instrument::findOrFail($request->validated('instrument_id')),
                $request->validated('quantity'),
                $request->validated('price'),
            ),
            TransactionType::Sell => $this->transactions->sell(
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
}
