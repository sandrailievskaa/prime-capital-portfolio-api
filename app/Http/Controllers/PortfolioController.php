<?php

namespace App\Http\Controllers;

use App\Http\Resources\CashBalanceResource;
use App\Http\Resources\HoldingResource;
use App\Models\Client;
use App\Services\PortfolioService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PortfolioController extends Controller
{
    public function __construct(
        private readonly PortfolioService $portfolio,
    ) {}

    public function cashBalance(Client $client): CashBalanceResource
    {
        return new CashBalanceResource($this->portfolio->cashBalance($client));
    }

    public function portfolio(Client $client): AnonymousResourceCollection
    {
        return HoldingResource::collection($this->portfolio->holdings($client));
    }
}
