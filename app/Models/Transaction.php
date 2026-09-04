<?php

namespace App\Models;

use App\Enums\TransactionType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['client_id', 'type', 'amount', 'instrument_id', 'quantity', 'price', 'transaction_fee'])]
class Transaction extends Model
{
    /**
     * Transactions are strictly append-only (CLAUDE.md rule 3: no PUT/PATCH,
     * no soft-delete-then-recreate, no correction endpoint — ever). An
     * `updated_at` column would imply a row can legitimately change after
     * creation, which contradicts that invariant. Disabling it here — and
     * omitting the column from the migration entirely — makes "this row
     * never changes" true at both the model and schema level, not just
     * enforced by convention in the write path.
     */
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
