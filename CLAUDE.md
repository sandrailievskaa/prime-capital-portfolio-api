# Project Context — Prime Capital Portfolio API

Backend-only Laravel REST API for a fictional investment firm. It tracks each
client's cash and instrument holdings purely from an append-only ledger of
transactions (`deposit`, `withdrawal`, `buy`, `sell`). No frontend, no auth.
This is a timed technical assessment — correctness, clean architecture, and a
legible, incremental Git history matter as much as working code.

This file is the single source of truth for business facts and hard
constraints. It does not replace judgment on architecture — several real
decisions below are explicitly left open for the Phase 1 design pass. If a
task prompt and this file ever seem to disagree, this file wins; ask before
assuming otherwise.

## Domain, in one paragraph

A `Client` maps to exactly one cash-and-holdings account, in one currency
(identified by name only — no auth, no PII). Whether that's a physical
`accounts` table or an implicit property of `Client` is an open architecture
question — see ADR-001, do not assume either answer here.
`Instrument`s are free-text tickers (e.g. "AAPL"), seeded ahead of time; a
buy/sell must reference one that already exists — the system never creates
an instrument implicitly from a transaction request. *(Note: the original
brief's "no list to pick from" means there's no closed dropdown of allowed
tickers — any string works as a label. It does **not** mean an Instrument
row is auto-created on first use. The client explicitly confirmed
instruments are pre-seeded and buy/sell must reference an existing one.)*
Everything that happens to a client's money or holdings is one `Transaction`
row. Nothing about that row's own arithmetic changes after it's written.

## Non-negotiable business rules

These came directly from the client. Do not renegotiate, "improve," or add
scope beyond them without asking first.

1. **One client → one account → one currency.** No auth, no multi-account.
   (Whether "account" is its own model — open, see ADR-001.)
2. **Instruments are pre-seeded**, never created implicitly via buy/sell.
   Referencing an unknown instrument is a validation error (422), not a
   silent create.
3. **Transactions are strictly immutable.** Insert-only. No `PUT`/`PATCH`,
   no soft-delete-then-recreate, no admin "correction" endpoint. Ever.
4. **Four transaction types** — `deposit`, `withdrawal`, `buy`, `sell` — as a
   backed PHP enum, not a raw string column.
5. **Precision**: `amount` (deposit/withdrawal) and `price` (buy/sell, per
   unit) are exactly 2 decimal places, `decimal(15,2)` columns — never a PHP
   float in any calculation path. `quantity` (buy/sell) is a positive
   integer, never fractional.
6. For buy/sell, the total (`quantity × price`) is **derived**, never
   accepted as input and never stored as an independently-editable column
   that could drift out of sync. (This closes a real exploit: if `amount`
   were accepted alongside `quantity`/`price`, nothing would stop
   `quantity=5, price=100, amount=1` from being submitted.)
7. **Cash balance and instrument holdings — and only those two things — are
   derived from the immutable transaction ledger on every read.** There is
   no cached `cash_balance` or `holding_quantity` column anywhere. This is a
   deliberate correctness/simplicity choice (the ledger is the only source
   of truth for *balance state*), confirmed explicitly by the client
   ("calculate for now; only add caching if a real performance problem shows
   up") — not an oversight. This rule is about *state*, not about
   *arithmetic in general*: `quantity × price`, validation checks, and
   aggregate `SUM()` queries obviously still happen constantly. Don't
   propose caching a balance column unless I ask you to revisit it.
8. **Cash must never go negative.** Reject withdrawal/buy if it would, leave
   all state untouched, return a specific error (not a generic 500).
9. **A client can never sell more units of an instrument than they currently
   hold.** Selling exactly the full held quantity (down to zero) is
   explicitly allowed and must succeed.
10. An instrument at **exactly zero held quantity must not appear at all**
    in the portfolio response — not `quantity: 0`, absent entirely.
11. Every write is **atomic**: on any rule violation, zero rows are written
    and zero balances change. In practice, the write path here is a single
    `INSERT` — which is already atomic at the storage-engine level on its
    own. `DB::transaction()` still matters, but for a more precise reason
    than "atomicity" as a buzzword: it's the container that would make a
    defensive `lockForUpdate()` read (see ADR-004) actually hold its lock
    until the insert commits, and it's the correct habitual boundary around
    any "read business state → decide → write" operation regardless of how
    many statements it happens to be today. Be ready to explain this
    precisely, not just say "we use a DB transaction for atomicity."
12. `transaction_fee` exists as a nullable/defaulted column on `transactions`
    for future use. It does **not** currently affect cash/holdings math.
    Verify this explicitly with a test before relying on it — don't let it
    silently get folded into a total.
13. **No authentication, no users, no Sanctum, no policies, no login.** The
    brief has none of this. Do not add it "because a REST API normally
    needs auth" — that's scope creep an evaluator will notice and penalize,
    not reward.
14. No concurrent-request handling is required for this assessment (client
    confirmed) — but rule 11 (atomicity) still holds regardless. Whether to
    defensively add `lockForUpdate()` anyway is a real trade-off worth an
    ADR, not an assumption either way. If adopted, lock the parent `Client`
    row (balance is a computed aggregate, not a single lockable row) —
    don't try to lock the `transactions` table itself.
15. Since `quantity` is always a whole integer, `quantity × price` never
    produces more than 2 decimal places — no rounding strategy is needed
    anywhere. If you find yourself writing a `round()` call near money,
    stop and reconsider.
16. Don't build a shared abstraction across deposit/withdraw/buy/sell merely
    because four methods happen to live on one service class. Check whether
    the behavior genuinely warrants a common code path before extracting
    one — a `TransactionService` that's four clear, separately-readable
    methods is a better assessment deliverable than a clever but harder-to-
    explain shared pipeline.

## Rules of engagement for you (the agent)

- **Never run `git commit` yourself.** Show me `git status` / `git diff`,
  explain what changed and why, and stop — I commit by hand after review.
- **Never create fake, placeholder, or meaningless commits merely to make
  the history look incremental.** Every commit I make corresponds to real,
  completed work I've reviewed and approved — not padding.
- **Never modify Git history or timestamps.** No backdating, no rebasing to
  reorder for appearances, no `--date` tricks. The history is exactly what
  it is.
- **Work exactly one phase at a time**, per whatever the current prompt
  says. Don't pull work forward from a later phase even if it's convenient.
- After finishing a phase: summarize what changed, show the relevant files,
  explain the non-obvious decisions, run the relevant checks, report
  failures honestly (don't paraphrase test output — show it), and **stop**.
- **Use Laravel Boost's MCP tools** to check real project state instead of
  assuming — `application-info`, `database-schema`, `list-routes`,
  `search-docs`, `tinker` (or `php artisan tinker` via bash if the Tinker
  MCP tool isn't registered), `last-error`, `read-log-entries`. If Boost and
  the code ever seem to disagree, investigate the real state — don't assume
  either one is automatically correct.
- **No floating-point arithmetic anywhere near money or quantity math.**
  Prefer `brick/money` (the client explicitly said this is preferred for
  precision) or BCMath with string-safe decimals as the fallback. Flag it
  immediately if you ever see a `(float)` cast or raw `+ - *` on a
  decimal-looking value.
- Prefer the simplest design that satisfies every rule above. No repository
  pattern, event sourcing, queues, caching, or microservice-flavored
  abstractions "for scalability" — this is a scoped assessment, not a
  production system that needs to survive next year.
- For every non-trivial architectural choice, name the alternative you
  considered and why you didn't pick it, in the same message — I need to be
  able to explain every decision out loud without re-reading the code.
- **If you think my proposed approach is wrong, say so directly and explain
  why.** Don't agree with me just because I suggested it. Do not accept a
  suspicious, incomplete, over-engineered, or CLAUDE.md-inconsistent result
  from your own earlier output either — flag it to me instead of quietly
  proceeding.

## Recommended (not fixed) API surface

Confirm/revise this in the Phase 1 design pass, but this is the sane default:

```
POST /api/clients/{client}/transactions      # type in body selects behavior
GET  /api/clients/{client}/cash-balance
GET  /api/clients/{client}/portfolio
GET  /api/clients/{client}/transactions       # history, paginated
```

## Directory conventions

- `app/Enums/TransactionType.php`
- `app/Models/{Client,Instrument,Transaction}.php`
- `app/Http/Requests/CreateTransactionRequest.php` (or per-type requests —
  decide and justify in Phase 1/3)
- `app/Services/PortfolioService.php` (read-side: balance, holdings)
- `app/Services/TransactionService.php` (write-side: deposit/withdraw/buy/sell)
- `app/Exceptions/{InsufficientFundsException,InsufficientHoldingsException}.php`
- `docs/adr/000X-title.md` (architecture decision records)
- `database/seeders/{ClientSeeder,InstrumentSeeder,TransactionSeeder}.php`
