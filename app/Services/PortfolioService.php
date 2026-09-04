<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\Client;
use App\Models\Instrument;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PortfolioService
{
    /**
     * Cash balance derived live from the ledger (rule 7) — no cached
     * column anywhere. Summed in PHP via brick/money's arbitrary-precision
     * arithmetic rather than a SQL SUM(): SQLite's NUMERIC-affinity columns
     * are computed internally as IEEE-754 doubles, which is not a
     * precision guarantee at the edges of decimal(15,2)'s range. This
     * keeps the entire calculation path free of floats end to end.
     */
    public function cashBalance(Client $client): Money
    {
        $balance = Money::zero($client->currency);

        foreach ($client->transactions()->get(['type', 'amount', 'quantity', 'price']) as $transaction) {
            $balance = match ($transaction->type) {
                TransactionType::Deposit => $balance->plus(Money::of($transaction->amount, $client->currency)),
                TransactionType::Withdrawal => $balance->minus(Money::of($transaction->amount, $client->currency)),
                // RoundingMode::Unnecessary stated explicitly, not relied
                // on as brick/money's default — same reasoning as
                // TransactionService::buy(): rule 15 guarantees this
                // multiplication never needs rounding, so if it ever did,
                // that's a bug that should throw, not one that silently
                // rounds if the library's default ever changes.
                TransactionType::Sell => $balance->plus(
                    Money::of($transaction->price, $client->currency)
                        ->multipliedBy($transaction->quantity, RoundingMode::Unnecessary)
                ),
                TransactionType::Buy => $balance->minus(
                    Money::of($transaction->price, $client->currency)
                        ->multipliedBy($transaction->quantity, RoundingMode::Unnecessary)
                ),
            };
        }

        return $balance;
    }

    /**
     * Instruments this client currently holds — quantity > 0 only (rule
     * 10). Plain SQL SUM() here, deliberately NOT cashBalance()'s
     * row-iteration pattern: quantity is an integer column, and SQLite's
     * integer arithmetic has no float-precision risk the way its
     * NUMERIC-affinity decimal columns do — the reasoning that justified
     * avoiding SUM() for money doesn't apply to whole-number quantities.
     *
     * The aggregate expression is written exactly once, in the inner
     * query's SELECT — the outer query filters on that inner alias in a
     * plain WHERE, not a same-level HAVING. This isn't just style: an
     * earlier version used `havingRaw('quantity > 0')` referencing the
     * SELECT alias directly, and SQLite does NOT reliably resolve an alias
     * in HAVING when it collides with a real column name on the base table
     * (`transactions.quantity` exists) — it silently returned rows it
     * should have filtered out instead of erroring (verified empirically,
     * see the regression test for this exact case). An outer query's WHERE
     * against an inner query's SELECT alias doesn't have that same-level
     * ambiguity, and duplicating the CASE expression is no longer needed.
     *
     * @return Collection<int, object{instrument_id: int, quantity: int}>
     */
    public function holdings(Client $client): Collection
    {
        $perInstrument = DB::table('transactions')
            ->select('instrument_id')
            ->selectRaw(
                'SUM(CASE WHEN type = ? THEN quantity ELSE 0 END) '.
                '- SUM(CASE WHEN type = ? THEN quantity ELSE 0 END) AS quantity',
                [TransactionType::Buy->value, TransactionType::Sell->value]
            )
            ->where('client_id', $client->id)
            ->whereIn('type', [TransactionType::Buy->value, TransactionType::Sell->value])
            ->groupBy('instrument_id');

        return DB::query()
            ->fromSub($perInstrument, 'holdings')
            ->where('quantity', '>', 0)
            ->get();
    }

    /**
     * Current held quantity for one instrument — used to validate a sell.
     * Unlike holdings(), this has no HAVING filter: a sell check needs the
     * true current quantity even when it's zero, not just the
     * positive-only display set. Returns 0 (not null) when nothing has
     * ever been bought.
     */
    public function holdingQuantity(Client $client, Instrument $instrument): int
    {
        $quantity = DB::table('transactions')
            ->where('client_id', $client->id)
            ->where('instrument_id', $instrument->id)
            ->whereIn('type', [TransactionType::Buy->value, TransactionType::Sell->value])
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN type = ? THEN quantity ELSE 0 END) '.
                '- SUM(CASE WHEN type = ? THEN quantity ELSE 0 END), 0) AS quantity',
                [TransactionType::Buy->value, TransactionType::Sell->value]
            )
            ->value('quantity');

        return (int) $quantity;
    }
}
