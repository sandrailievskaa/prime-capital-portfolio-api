<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'type' => $this->type->value,
            'amount' => $this->amount,
            'instrument_id' => $this->instrument_id,
            'quantity' => $this->quantity,
            'price' => $this->price,
            'transaction_fee' => $this->transaction_fee,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
