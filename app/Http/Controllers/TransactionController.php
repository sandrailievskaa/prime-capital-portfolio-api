<?php

namespace App\Http\Controllers;

use App\Enums\TransactionType;
use App\Http\Requests\CreateTransactionRequest;
use App\Http\Resources\TransactionResource;
use App\Models\Client;
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
        $amount = $request->validated('amount');

        $transaction = match ($type) {
            TransactionType::Deposit => $this->transactions->deposit($client, $amount),
            TransactionType::Withdrawal => $this->transactions->withdraw($client, $amount),
            // Buy/sell come next phase — CreateTransactionRequest already
            // validates their shape structurally, but TransactionService
            // doesn't implement them yet.
            TransactionType::Buy, TransactionType::Sell => abort(501, 'Buy/sell processing is not implemented yet.'),
        };

        return TransactionResource::make($transaction)
            ->response()
            ->setStatusCode(201);
    }
}
