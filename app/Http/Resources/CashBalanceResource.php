<?php

namespace App\Http\Resources;

use Brick\Money\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wraps a brick/money Money value (never a float — ADR-002). Currency
 * comes from the Money object itself, which PortfolioService always
 * constructs from the client's own `currency` column — never assumed.
 *
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
