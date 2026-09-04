<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreInstrumentRequest;
use App\Http\Resources\InstrumentResource;
use App\Models\Instrument;
use Illuminate\Http\JsonResponse;

class InstrumentController extends Controller
{
    public function store(StoreInstrumentRequest $request): JsonResponse
    {
        $instrument = Instrument::create([
            'ticker' => $request->validated('ticker'),
        ]);

        return InstrumentResource::make($instrument)
            ->response()
            ->setStatusCode(201);
    }
}
