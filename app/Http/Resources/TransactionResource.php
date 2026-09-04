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
            'type' => $this->type->value,
            'amount' => $this->amount,
            'instrument' => $this->instrument ? InstrumentResource::make($this->instrument) : null,
            'quantity' => $this->quantity,
            'price' => $this->price,
            'transaction_fee' => $this->transaction_fee,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
