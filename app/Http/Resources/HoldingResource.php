<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property object{instrument_id: int, ticker: string, quantity: int} $resource
 */
class HoldingResource extends JsonResource
{
    /**
     * @return array<string, int|string>
     */
    public function toArray(Request $request): array
    {
        return [
            'instrument_id' => (int) $this->resource->instrument_id,
            'ticker' => $this->resource->ticker,
            'quantity' => (int) $this->resource->quantity,
        ];
    }
}
