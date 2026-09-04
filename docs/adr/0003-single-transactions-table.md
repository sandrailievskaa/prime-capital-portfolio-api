# ADR-003: Single `transactions` Table with Nullable Per-Type Columns

**Status:** Accepted   **Date:** 2026-09-04

## Context

CLAUDE.md's domain paragraph states, in nearly these exact words: "Everything that happens to a client's money or holdings is one `Transaction` row." Rule 4 separately requires the four transaction types to be modeled "as a backed PHP enum, not a raw string column" — phrasing that only makes sense if all four types live in the same table distinguished by a single `type` column (a "raw string column" is something you'd only warn against if the alternative — a shared column — were already the assumed design). Rule 7 requires cash balance and holdings to be derived live from the ledger via aggregation. `deposit`/`withdrawal` need only an `amount`; `buy`/`sell` need `instrument_id`, `quantity`, and `price` (never a stored total, per rule 6).

## Decision

One `transactions` table. A backed PHP enum `TransactionType` (`deposit`, `withdrawal`, `buy`, `sell`) drives a `type` column. `amount` is nullable, populated only for `deposit`/`withdrawal`. `instrument_id`, `quantity`, `price` are nullable, populated only for `buy`/`sell`. Validity of "the right columns are populated for this type" is enforced at the `TransactionService`/`FormRequest` layer, since transactions are only ever created through that path — never via a raw insert.

## Options Considered

### Option A — Single table, nullable per-type columns (chosen)

**Pros:**
- Matches the domain paragraph's own wording literally: one `Transaction` model, one row per event, one ordered timeline.
- Rule 4's "not a raw string column" phrasing implies exactly this shape — a shared `type` enum column distinguishing rows in one table.
- Cash balance and holdings (rule 7) become simple, single-table aggregate queries: `SUM(CASE WHEN type IN ('deposit','sell') THEN ... WHEN type IN ('withdrawal','buy') THEN -... END)`. One query, one table, naturally ordered by `id`/`created_at`.
- `GET /clients/{client}/transactions` (paginated history, per CLAUDE.md's recommended API surface) is a single `ORDER BY id`/`LIMIT`/`OFFSET` query — no merging of multiple result sets.

**Cons:**
- Nullable columns: a `deposit` row has `NULL` `instrument_id`/`quantity`/`price`; a `buy` row has `NULL` `amount`. This is a real, visible "smell" under strict relational-normalization review.
- No trivial single DB-level CHECK constraint enforcing "amount is set XOR (instrument_id, quantity, price are all set)" without extra work (though one can be added — see Consequences).

### Option B — Separate tables per type (`deposits`, `withdrawals`, `buys`, `sells`)

**Pros:**
- Each table has exactly the columns its type needs — no nulls, tighter per-type `NOT NULL`/type constraints, cleaner from a pure-normalization standpoint.

**Cons:**
- Directly contradicts the domain paragraph's "one `Transaction` row" statement and rule 4's enum-column phrasing.
- Computing cash balance or holdings (rule 7) requires a `UNION ALL` across four tables with different column shapes, then aggregating — meaningfully more complex than a single-table `SUM`.
- `GET /clients/{client}/transactions` (paginated, chronological history across all types) requires merging and re-sorting four independently-paginated queries — genuinely awkward to implement correctly, and a real risk of subtly wrong pagination (e.g., page 2 skipping or duplicating rows across the merge boundary).
- Four Eloquent models instead of one, with either duplicated validation/atomicity logic or an awkward shared base class — working against rule 16's instruction not to force a shared abstraction unless behavior genuinely warrants it, and here the *behavior itself* (unified ledger ordering and aggregation) argues for unification, not against it.

## Consequences

- `transactions` table: `id`, `client_id`, `type` (enum), `amount` (nullable decimal(15,2)), `instrument_id` (nullable FK), `quantity` (nullable unsigned int), `price` (nullable decimal(15,2)), `transaction_fee` (nullable/defaulted, per rule 12, currently inert), timestamps.
- **Decided: the `CHECK` constraint is added in the Phase 2 migration**, not left optional — because this ledger is append-only and rows are never edited or deleted, a single invalid row born through any bypass of `TransactionService` (raw tinker, a future seeder bug, a refactor that forgets validation) would be a permanent, unfixable corruption of the source of truth, and a database-level constraint closes that risk for a one-line cost regardless of what application code does later.
- `TransactionService` is the only writer; no other code path should ever insert into `transactions` directly.

---

### Answers to the standard question set

1. **What does the requirement force? What does it NOT require?** It forces one `Transaction` row per event and a shared `type` enum column (both stated close to literally in CLAUDE.md). It does not force *how* per-type data is stored — nullable typed columns is one valid mechanism; a JSON "details" column was considered and rejected below.
2. **Simplest vs. sophisticated:** The single wide table (chosen) is the simpler valid solution. A more "sophisticated" alternative beyond Option B would be class-table inheritance (a base `transactions` table plus per-type detail tables joined 1:1) — more normalized, but adds a join to every single query for a fixed set of exactly four types that will never grow within this assessment's scope. The simpler option is correct because query simplicity and ledger unification are actual stated requirements (rule 7); normalization purity is not.
3. **At real scale:** if transaction types grew unbounded (dividends, fees, transfers, corporate actions — each with very different fields), a wide nullable table would become unmanageable (dozens of sparse columns). At that point, either class-table inheritance, a JSONB "details" column with typed application-layer payload classes, or an event-sourcing-style log becomes the right call. With exactly four fixed, well-understood types, none of that complexity is warranted yet.
4. **Likely interviewer challenge:** "Doesn't a table full of nullable columns violate normalization?" — Yes, in the general case; here it's the standard, well-understood pattern for a small, bounded, fixed set of discriminated event types that share one timeline (the same shape used by most ledger/audit-log tables in production fintech systems, and by patterns like single-table inheritance). Likely follow-up: "How do you stop a bad row (e.g., a `deposit` with a `quantity` set) from ever being written?" — Answered structurally: the only write path is `TransactionService`, validated by a `FormRequest` before it ever reaches the service. A DB `CHECK` constraint was originally added as a second line of defense — see the Amendment below for why that was removed.

## Amendment (post-acceptance): the XOR `CHECK` constraint was dropped

The Consequences section above states the `CHECK` constraint is "decided, not optional." That was revisited, deliberately, not silently:

- Implementing it required the `transactions` migration to be raw `DB::statement()` SQL end to end — this Laravel version's `Blueprint` has no fluent multi-column `CHECK` method, and SQLite has no `ALTER TABLE ... ADD CONSTRAINT` to add one after `CREATE TABLE`, so there was no way to keep the constraint *and* use Laravel's schema builder for the rest of the table.
- The client's explicit direction was: migrations should use Laravel's Blueprint/`Schema::create()` syntax, not plain SQL.
- The constraint was genuinely defense-in-depth, not the actual enforcement mechanism: `TransactionService` is the only code path that ever inserts into `transactions`, and `CreateTransactionRequest`'s `required_if`/`prohibited_if` rules already enforce the same XOR shape before a request reaches the service. No raw insert path (tinker, a seeder bug, a future refactor) is expected in this assessment's scope, and CLAUDE.md's own architectural guidance favors the simplest design that satisfies the stated rules over speculative extra layers.
- **Decision:** the migration was rewritten in pure Blueprint syntax (`database/migrations/2026_09_04_140002_create_transactions_table.php`). The `type` column still gets a real `CHECK (type IN (...))` for free — Laravel's `$table->enum()` compiles to that natively on SQLite — but the amount-XOR-instrument fields constraint is no longer enforced at the database level, only at the `FormRequest`/service layer.
- **Trade-off being accepted:** a hand-crafted raw SQL `INSERT` that bypasses the application entirely (bulk import script, manual `DB::table()->insert()`, a future direct-DB migration tool) could now write a structurally invalid row. This is judged acceptable for this project's scope; if that changes, the correct fix is reintroducing the raw-SQL `CREATE TABLE` from this migration's prior version, not a partial workaround.
