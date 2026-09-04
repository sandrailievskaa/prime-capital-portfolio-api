<?php

namespace App\Models;

use App\Enums\TransactionType;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

#[Unguarded]
class Client extends Model
{
    /** @return HasMany<Transaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    protected function cashBalance(): Attribute
    {
        return Attribute::make(get: function (): Money {
            $balance = Money::zero($this->currency);

            foreach ($this->transactions()->get(['type', 'amount', 'quantity', 'price']) as $transaction) {
                $balance = match ($transaction->type) {
                    TransactionType::Deposit => $balance->plus(Money::of($transaction->amount, $this->currency)),
                    TransactionType::Withdrawal => $balance->minus(Money::of($transaction->amount, $this->currency)),
                    TransactionType::Sell => $balance->plus(
                        Money::of($transaction->price, $this->currency)->multipliedBy($transaction->quantity, RoundingMode::Unnecessary)
                    ),
                    TransactionType::Buy => $balance->minus(
                        Money::of($transaction->price, $this->currency)->multipliedBy($transaction->quantity, RoundingMode::Unnecessary)
                    ),
                };
            }

            return $balance;
        })->withoutObjectCaching();
    }

    /** @return Attribute<Collection<int, object{instrument_id: int, ticker: string, quantity: int}>, never> */
    protected function holdings(): Attribute
    {
        return Attribute::make(get: function (): Collection {
            $perInstrument = DB::table('transactions')
                ->select('instrument_id')
                ->selectRaw(
                    'SUM(CASE WHEN type = ? THEN quantity ELSE 0 END) '.
                    '- SUM(CASE WHEN type = ? THEN quantity ELSE 0 END) AS quantity',
                    [TransactionType::Buy->value, TransactionType::Sell->value]
                )
                ->where('client_id', $this->id)
                ->whereIn('type', [TransactionType::Buy->value, TransactionType::Sell->value])
                ->groupBy('instrument_id');

            // Outer WHERE on the subquery alias, not HAVING: SQLite doesn't
            // reliably resolve a HAVING alias that collides with a real column
            // name (transactions.quantity) — see PortfolioZeroHoldingsTest.
            return DB::query()
                ->fromSub($perInstrument, 'holdings')
                ->join('instruments', 'instruments.id', '=', 'holdings.instrument_id')
                ->where('holdings.quantity', '>', 0)
                ->select('holdings.instrument_id', 'instruments.ticker', 'holdings.quantity')
                ->get();
        })->withoutObjectCaching();
    }
}
