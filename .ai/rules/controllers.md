---
paths:
  - 'app/Http/Controllers/**'
  - 'routes/api.php'
---

# Controllers

## Transactions are strictly insert-only, never mutated
No PUT/PATCH, no soft-delete-then-recreate, no admin "correction" endpoint — ever (client-confirmed business rule). Transaction::UPDATED_AT is null and the column is omitted from the migration entirely, so "this row never changes" holds at the schema level, not just by convention. Only POST (create) and GET (read) routes exist for transactions.

## Boundary check is strictly-greater-than, not gte — exact zero landing is allowed
Withdrawing/spending exactly the full cash balance, and selling exactly the full held quantity, must succeed (client-confirmed). Only a request strictly exceeding available balance/holdings is rejected with InsufficientFundsException/InsufficientHoldingsException (422, error_code insufficient_funds/insufficient_holdings). Don't "fix" this to >= — that would incorrectly reject the boundary case.

## Every write: DB::transaction() + lockForUpdate() on the Client row
Client::lockForUpdate()->findOrFail() first, inside DB::transaction(), before any read-business-state→decide→write sequence. Reasoning is NOT "the INSERT needs atomicity" (a single INSERT is already atomic at the storage engine) — it's that DB::transaction() is what makes the lock actually hold until commit, and it's the correct habitual boundary regardless of statement count. Lock the parent Client row (balance/holdings are computed aggregates, not a single lockable row) — never try to lock the transactions table itself.

## Don't extract a shared pipeline across deposit/withdraw/buy/sell
TransactionController::store() dispatches to four private methods (deposit/withdraw/buy/sell), not a generic "lock, check, insert" template — deposit/withdraw carry only amount; buy/sell validate a fundamentally different check (Money comparison vs. integer comparison) and carry instrument_id/quantity/price. A shared abstraction here would hide that difference behind a callback for no real benefit. Same reasoning: one FormRequest with required_if/prohibited_if branching on type, not four per-type request classes.

## No service layer — business logic lives in controllers
TransactionService/PortfolioService were removed; deposit/withdraw/buy/sell logic lives as private methods on TransactionController, and read-side balance/holdings computation lives on the Client model (see .ai/rules/models.md) — not in a separate service class.
