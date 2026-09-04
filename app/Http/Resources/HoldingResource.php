<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wraps one row of PortfolioService::holdings() — quantity > 0 only
 * (rule 10), enforced by that method's subquery, not here. This resource
 * does not re-filter or re-query; it formats whatever holdings() already
 * decided belongs in the response.
 *
 * @property object{instrument_id: int, quantity: int} $resource
 */
class HoldingResource extends JsonResource
{
    /**
     * @return array<string, int>
     */
    public function toArray(Request $request): array
    {
        return [
            'instrument_id' => (int) $this->resource->instrument_id,
            'quantity' => (int) $this->resource->quantity,
        ];
    }
}
