<?php

namespace App\Http\Resources;

use Brick\Money\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Money $resource
 */
class CashBalanceResource extends JsonResource
{
    /**
     * @return array<string, string>
     */
    public function toArray(Request $request): array
    {
        return [
            'currency' => $this->resource->getCurrency()->getCurrencyCode(),
            'balance' => (string) $this->resource->getAmount(),
        ];
    }
}
