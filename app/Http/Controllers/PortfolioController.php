<?php

namespace App\Http\Controllers;

use App\Http\Resources\HoldingResource;
use App\Models\Client;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PortfolioController extends Controller
{
    public function show(Client $client): AnonymousResourceCollection
    {
        return HoldingResource::collection($client->holdings);
    }
}
