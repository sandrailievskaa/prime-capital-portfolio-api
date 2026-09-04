<?php

namespace App\Models;

use App\Enums\TransactionType;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Unguarded]
class Transaction extends Model
{
    /** Transactions are append-only; no updated_at column exists. */
    const UPDATED_AT = null;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'amount' => 'decimal:2',
            'quantity' => 'integer',
            'price' => 'decimal:2',
            'transaction_fee' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<Instrument, $this> */
    public function instrument(): BelongsTo
    {
        return $this->belongsTo(Instrument::class);
    }
}
