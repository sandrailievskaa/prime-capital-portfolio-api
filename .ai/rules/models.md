---
paths:
  - 'app/Models/**'
  - app/Models/Client.php
---

# Models

## No floats near money — brick/money only, decimal(15,2)/integer columns
amount and price are decimal(15,2), never a PHP float in any calculation path; quantity is a positive integer, never fractional. Use brick/money (Money::of / multipliedBy) with RoundingMode::Unnecessary stated explicitly — quantity is always a whole integer so quantity×price never needs rounding; if it ever throws, that's a real bug, not something to silently round away. Flag any (float) cast or raw +-* on a decimal-looking value immediately.

## Use #[Unguarded], relations, scopes, and Attribute accessors only — no plain business-logic methods
Client/Instrument/Transaction are #[Unguarded] (Laravel 13's per-model attribute, not a global Model::unguard() call) rather than #[Fillable(...)] — every write already goes through a validated FormRequest before reaching Model::create(), so per-field guarding is redundant. Client exposes cashBalance() and holdings() as protected Attribute-returning methods (accessed as ->cash_balance / ->holdings), not plain public methods — a parameterized lookup like "quantity held for one instrument" is derived by callers from ->holdings (firstWhere + ?? 0) rather than living as a model method, since Attribute accessors can't take arguments.

## Cash balance and holdings are always derived live from the ledger
No cached cash_balance or holding_quantity column anywhere. This is deliberate (client: "calculate for now; only add caching if a real performance problem shows up") — don't propose caching a balance column unless asked to revisit it.

## Zero-holdings filter: outer WHERE on a subquery alias, never HAVING
An instrument at exactly zero held quantity must be entirely absent from the portfolio response, never quantity: 0. A prior version used havingRaw('quantity > 0') referencing the SELECT alias — SQLite doesn't reliably resolve that alias in HAVING when it collides with a real column name (transactions.quantity), and silently returned rows it should have filtered. Current shape (Client::holdings() Attribute accessor): inner query computes the aggregate once as `quantity`, then joins `instruments` (for `ticker`) before the outer fromSub()->where('holdings.quantity', '>', 0) filter. Regression test: tests/Feature/PortfolioZeroHoldingsTest.php.

## cash_balance/holdings Attribute accessors must use withoutObjectCaching()
Eloquent caches an accessor's return value on the model instance once it's an object (Money, Collection) — without ->withoutObjectCaching(), a second access to $client->cash_balance or $client->holdings on the same PHP object returns the stale first-computed value even after the underlying transactions changed (e.g. across two HTTP requests hitting the same $client instance in a test). This directly violates rule 7 ("derived from the ledger on every read"). Caught by LedgerWorkflowTest failing after the Client attribute conversion — always keep ->withoutObjectCaching() on both.
