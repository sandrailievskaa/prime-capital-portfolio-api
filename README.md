# Prime Capital Portfolio API

A backend-only Laravel REST API for a fictional investment firm. It tracks each
client's cash and instrument holdings purely from an append-only ledger of
transactions — `deposit`, `withdrawal`, `buy`, `sell`. There is no cached
balance or holdings column anywhere: every read recomputes the current state
from the transaction history. There is no frontend and no authentication.

## Requirements

- PHP 8.3+
- Composer
- SQLite (bundled with PHP's PDO extension — no separate database server needed)

## Setup

```bash
git clone <this-repo>
cd prime-capital-portfolio-api

composer install

cp .env.example .env
php artisan key:generate

touch database/database.sqlite   # Windows: New-Item database/database.sqlite

php artisan migrate --seed
```

Then start the app:

```bash
php artisan serve
```

By default this serves on `http://127.0.0.1:8000`; all examples below assume
that base URL.

## Seeded data

`php artisan migrate --seed` runs three seeders (`InstrumentSeeder`,
`ClientSeeder`, `TransactionSeeder`) and leaves the database in a small,
deliberate demo state — no random/factory noise:

| Client | id | Currency | Cash balance | Holdings |
|---|---|---|---|---|
| Ana | 1 | EUR | 860.00 | AAPL: 2, MSFT: 5 |
| Marko | 2 | USD | 200.00 | TSLA: 10 |
| Elena | 3 | USD | 300.00 | *(none)* |

Ana's ledger reproduces the client's own worked example from the brief (1000
deposit → buy 5 AAPL @ 100 → buy 5 MSFT @ 100 → redeposit 500 → sell 3 AAPL @
120), stopped at the checkpoint where the balance and AAPL holding above are
directly verifiable — before the brief's final "sell the remaining 2 AAPL"
step, which would zero that holding back out.

Instruments seeded: `AAPL` (id 1), `TSLA` (id 2), `MSFT` (id 3).

> **Note:** there is currently no `POST /api/clients` endpoint — creating a
> client is only possible via `Client::create(...)` in Tinker or a seeder.
> The three clients above are the only ones available out of the box.

## API

Every response is a JSON object. Successful responses wrap their payload in
`data` (Laravel's standard API Resource envelope); list endpoints add
`links`/`meta` for pagination. Error responses always carry `error_code` and
`message`; a 422 from field-level validation additionally carries a Laravel
`errors` map (see [Error responses](#error-responses) below).

### `POST /api/instruments`

Pre-seeds an instrument so it can later be referenced by a `buy`/`sell`. An
instrument is never created implicitly from a transaction.

```bash
curl -X POST http://127.0.0.1:8000/api/instruments \
  -H "Content-Type: application/json" \
  -d '{"ticker":"GOOG"}'
```

```json
{"data":{"id":4,"ticker":"GOOG","created_at":"2026-09-04T21:14:50.000000Z"}}
```
`201 Created`. Sending a ticker that already exists returns `422` with
`error_code: "validation_failed"`.

### `GET /api/clients/{client}/cash-balance`

```bash
curl http://127.0.0.1:8000/api/clients/1/cash-balance
```

```json
{"data":{"currency":"EUR","balance":"860.00"}}
```

### `GET /api/clients/{client}/portfolio`

Only instruments currently held in a positive quantity appear — an
instrument sold down to exactly zero is absent entirely, not present with
`"quantity": 0`.

```bash
curl http://127.0.0.1:8000/api/clients/1/portfolio
```

```json
{"data":[
  {"instrument_id":1,"ticker":"AAPL","quantity":2},
  {"instrument_id":3,"ticker":"MSFT","quantity":5}
]}
```

A client holding nothing (e.g. client 3, Elena) returns `{"data":[]}`.

### `GET /api/clients/{client}/transactions`

Full paginated ledger history, oldest first.

```bash
curl http://127.0.0.1:8000/api/clients/1/transactions
```

```json
{
  "data": [
    {"id":1,"type":"deposit","amount":"1000.00","instrument":null,"quantity":null,"price":null,"transaction_fee":"0.00","created_at":"2026-09-04T21:14:22.000000Z"},
    {"id":2,"type":"buy","amount":null,"instrument":{"id":1,"ticker":"AAPL","created_at":"2026-09-04T21:14:22.000000Z"},"quantity":5,"price":"100.00","transaction_fee":"0.00","created_at":"2026-09-04T21:14:22.000000Z"},
    {"id":3,"type":"buy","amount":null,"instrument":{"id":3,"ticker":"MSFT","created_at":"2026-09-04T21:14:22.000000Z"},"quantity":5,"price":"100.00","transaction_fee":"0.00","created_at":"2026-09-04T21:14:22.000000Z"},
    {"id":4,"type":"deposit","amount":"500.00","instrument":null,"quantity":null,"price":null,"transaction_fee":"0.00","created_at":"2026-09-04T21:14:22.000000Z"},
    {"id":5,"type":"sell","amount":null,"instrument":{"id":1,"ticker":"AAPL","created_at":"2026-09-04T21:14:22.000000Z"},"quantity":3,"price":"120.00","transaction_fee":"0.00","created_at":"2026-09-04T21:14:22.000000Z"}
  ],
  "links": {"first":"...","last":"...","prev":null,"next":null},
  "meta": {"current_page":1,"last_page":1,"per_page":15,"total":5}
}
```

A transaction row never carries `client_id` (the response is already scoped
by the client in the URL) and always shows `transaction_fee` as `"0.00"`,
never `null` — see
[docs/incidents/0001](docs/incidents/0001-transaction-fee-null-in-responses.md)
for the bug this guards against. `instrument` is the full related
instrument object for `buy`/`sell`, and `null` for `deposit`/`withdrawal`.

### `POST /api/clients/{client}/transactions`

One endpoint for all four transaction types; `type` in the body selects the
behavior. `amount`/`price` must be sent as **JSON strings** (`"10.50"`), not
bare numbers — a bare `10.50` is indistinguishable from `10.5` once PHP
parses it, which breaks the "exactly 2 decimal places" guarantee.

**Deposit / withdrawal:**

```bash
curl -X POST http://127.0.0.1:8000/api/clients/2/transactions \
  -H "Content-Type: application/json" \
  -d '{"type":"deposit","amount":"50.00"}'
```
```json
{"data":{"id":9,"type":"deposit","amount":"50.00","instrument":null,"quantity":null,"price":null,"transaction_fee":"0.00","created_at":"2026-09-04T21:14:50.000000Z"}}
```

**Buy / sell** (`instrument_id`, `quantity`, `price` — `price` is per unit,
the total is always derived, never accepted as input):

```bash
curl -X POST http://127.0.0.1:8000/api/clients/2/transactions \
  -H "Content-Type: application/json" \
  -d '{"type":"buy","instrument_id":1,"quantity":1,"price":"10.00"}'
```
```json
{"data":{"id":10,"type":"buy","amount":null,"instrument":{"id":1,"ticker":"AAPL","created_at":"2026-09-04T21:14:22.000000Z"},"quantity":1,"price":"10.00","transaction_fee":"0.00","created_at":"2026-09-04T21:14:50.000000Z"}}
```

All four types return `201 Created` on success.

## Error responses

Every failure carries `error_code` and `message`. Business-rule rejections
and 404s carry nothing else; a 422 from `StoreTransactionRequest`'s own field
validation additionally carries an `errors` map.

| Case | Status | `error_code` |
|---|---|---|
| Withdraw/buy more than available cash | 422 | `insufficient_funds` |
| Sell more than currently held | 422 | `insufficient_holdings` |
| `instrument_id` doesn't exist | 422 | `unknown_instrument` |
| `amount`/`price` sent as a bare number, wrong shape, unknown field, etc. | 422 | `validation_failed` |
| Client doesn't exist | 404 | `not_found` |

```bash
curl -X POST http://127.0.0.1:8000/api/clients/3/transactions \
  -H "Content-Type: application/json" \
  -d '{"type":"withdrawal","amount":"999999.00"}'
```
```json
{"error_code":"insufficient_funds","message":"Insufficient funds for client 3: requested USD 999999.00, available USD 300.00."}
```

```bash
curl -X POST http://127.0.0.1:8000/api/clients/3/transactions \
  -H "Content-Type: application/json" \
  -d '{"type":"sell","instrument_id":1,"quantity":1,"price":"10.00"}'
```
```json
{"error_code":"insufficient_holdings","message":"Insufficient holdings for client 3 in instrument AAPL: requested 1, held 0."}
```

```bash
curl -X POST http://127.0.0.1:8000/api/clients/1/transactions \
  -H "Content-Type: application/json" \
  -d '{"type":"deposit","amount":10.50}'
```
```json
{"error_code":"validation_failed","message":"The amount field must be a JSON string (e.g. \"10.50\"), not a number — this is the only way to reliably preserve exactly 2 decimal places.","errors":{"amount":["The amount field must be a JSON string (e.g. \"10.50\"), not a number — this is the only way to reliably preserve exactly 2 decimal places."]}}
```

```bash
curl http://127.0.0.1:8000/api/clients/999999/cash-balance
```
```json
{"error_code":"not_found","message":"No query results for model [App\\Models\\Client] 999999"}
```

A rejected write leaves every row and every balance exactly as they were —
nothing is partially applied.

## Running tests

```bash
composer test
# or directly:
php artisan test
vendor/bin/phpunit
```

The suite currently has 59 tests / 201 assertions, all passing.

## Why this way

This section is a summary; the full reasoning for each decision — including
the alternatives considered and why they were rejected — lives in
[`docs/adr/`](docs/adr/).

- **Derived state, never cached.** Cash balance and instrument holdings are
  recomputed from the `transactions` table on every read; there is no
  `cash_balance` or `holding_quantity` column anywhere. See
  [ADR-001](docs/adr/0001-client-owns-account-directly.md).
- **Strict immutability.** Transactions are insert-only — no `PUT`/`PATCH`,
  no soft-delete-then-recreate, no correction endpoint. The `Transaction`
  model has no `updated_at` column at all, so "this row never changes"
  holds at the schema level, not just by convention. See
  [ADR-003](docs/adr/0003-single-transactions-table.md).
- **Buy/sell price + quantity, never a stored total.** A `buy`/`sell`
  request supplies `quantity` and a per-unit `price`; the total is always
  derived (`quantity × price`) and never accepted as input, closing an
  exploit where a mismatched total could be submitted independently of the
  real quantity/price. See [ADR-003](docs/adr/0003-single-transactions-table.md).
- **One account per client, no separate `Account` model.** `Client` owns its
  `currency` directly; there's no `accounts` table. See
  [ADR-001](docs/adr/0001-client-owns-account-directly.md) for why a
  separate model would have added a join for no behavioral benefit here.
- **Atomicity.** Every write runs inside `DB::transaction()` with a
  defensive `Client::lockForUpdate()` taken first — not because a single
  `INSERT` needs it (it's already atomic on its own), but because the
  operation is really read-balance → decide → write, and the transaction
  boundary is what makes the lock actually hold until the insert commits.
  See [ADR-004](docs/adr/0004-atomicity-and-locking.md).
