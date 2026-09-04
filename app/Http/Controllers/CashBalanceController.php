<?php

namespace App\Http\Controllers;

use App\Http\Resources\CashBalanceResource;
use App\Models\Client;

class CashBalanceController extends Controller
{
    public function show(Client $client): CashBalanceResource
    {
        return new CashBalanceResource($client->cash_balance);
    }
}
