# ADR-001: Client Owns the Account/Currency Relationship Directly (No Separate Account Model)

**Status:** Accepted   **Date:** 2026-09-04

## Context

CLAUDE.md states as a domain fact: "A `Client` maps to exactly one cash-and-holdings account, in one currency" and explicitly leaves open "Whether that's a physical `accounts` table or an implicit property of `Client`... do not assume either answer here." Rule 1 hard-locks the cardinality: one client → one account → one currency, no auth, no multi-account, ever, for this assessment. Rule 7 separately establishes that cash balance and holdings are *never* stored anywhere — they are computed live from the `transactions` ledger on every read. This second fact matters a great deal for this decision: whatever model owns "the account" would not hold a `cash_balance` or `holding_quantity` column regardless of which option we pick, because rule 7 forbids that for both.

## Decision

`Client` owns the account/currency relationship directly. There is no separate `Account` model or `accounts` table. `Client` gets a `currency` column (a fixed ISO-4217-shaped string, e.g. `char(3)`), **required with no default value** — every `Client` row must supply its own currency explicitly at creation time. `Transaction` rows reference `client_id` directly (not an `account_id`).

**Correction from an earlier draft of this ADR:** an earlier version of this decision defaulted `currency` to `'USD'`. That default was an unjustified assumption — nothing in CLAUDE.md or the brief specifies a default currency, and inventing one would silently bake in a business decision the client never made. If two different clients are seeded with different currencies during testing (plausible, given the domain paragraph explicitly calls out "in one currency" per client, implying it can vary *between* clients), a hidden `'USD'` default could mask a seeder bug that forgot to set it, rather than surfacing a clear validation error. `currency` is therefore `NOT NULL` with no default at the column level, and required (not nullable, not optional) in `ClientSeeder`/any future client-creation path — there is no value this system is entitled to assume on the client's behalf.

## Options Considered

### Option A — Separate `Account` model (1:1 with Client)

**Pros:**
- Models the domain noun ("account") explicitly rather than folding it into `Client`, which reads more naturally if you think of "client" as an identity/KYC concept and "account" as a distinct financial concept.
- Provides a clean seam if the business ever needs multiple accounts per client (e.g., one per currency, or segregated account types) — `Transaction.account_id` would already exist and `Client` would just gain a `hasMany(Account::class)`.
- Currency conceptually "belongs" to the account, not the person, in most real brokerage domain models.

**Cons:**
- Given rule 7, the `Account` row would store almost nothing — just a `currency` column and a `client_id` foreign key. A table whose only job is to 1:1-wrap another table's primary key with one extra column is a strong "this is premature" smell.
- Adds a mandatory join to every single query that needs currency or that needs to resolve "this client's account" (which, for this assessment, is every query).
- Directly conflicts with the CLAUDE.md rules-of-engagement instruction: "Prefer the simplest design that satisfies every rule above... this is a scoped assessment, not a production system that needs to survive next year," and "don't design for hypothetical future requirements."
- Rule 1 guarantees 1:1 cardinality permanently within this assessment's scope — there is no near-term behavior that would ever read `Account` as a genuine one-to-many relationship.

### Option B — `Client` owns account/currency directly (chosen)

**Pros:**
- Simplest schema that satisfies every stated rule: one table, one FK target for `Transaction`, no join needed to resolve "this client's financial identity."
- The 1:1 invariant from rule 1 is enforced *structurally* — there is no second table whose rows could (through a bug) ever drift out of 1:1 correspondence with `Client`, because there is no second table.
- Matches CLAUDE.md's explicit preference for minimal abstraction and its rule-16 spirit (don't split a concept into multiple moving parts unless behavior genuinely requires it) applied to schema design, not just service classes.

**Cons:**
- If a genuine multi-account requirement appears later, this needs a real migration: introduce `accounts`, backfill one row per existing `Client`, add `account_id` to `Transaction`, migrate all existing rows, then decide whether `client_id` stays as a denormalized convenience or is dropped. Not a trivial change, though it is a well-understood one (a straightforward "promote an implicit 1:1 relationship to an explicit table" refactor).

## Consequences

- `clients` table gets a `currency` column, `NOT NULL`, no schema-level default; no `accounts` table exists.
- `transactions.client_id` is the only ownership FK needed for the ledger.
- `PortfolioService`/`TransactionService` operate directly against `Client`, with no intermediate `Account` resolution step.
- If multi-account support is ever requested, this ADR should be revisited and superseded — it is not a decision meant to survive that requirement, only this one.

---

### Answers to the standard question set

1. **What does the requirement force? What does it NOT require?** It forces a 1:1, permanently-single relationship between a client and their financial state (cash + holdings), addressed with no auth/session concept. It does *not* require a physically separate table to represent that relationship, and does not require any multi-currency conversion logic — currency is recorded as a fact, not acted upon.
2. **Simplest vs. sophisticated:** The simplest valid solution is Option B (fields on `Client`). The more sophisticated one is Option A (separate `Account`). The simpler one is correct here specifically because rule 7 already strips away the one thing (`cash_balance`/`holding_quantity` caching) that would have given `Account` real substance as its own row — without that, `Account` is a table with one meaningful column.
3. **At real scale:** If the product genuinely needed multiple accounts per client (e.g., segregated by currency or account type), Option A becomes the correct model, and the migration path described above is the mechanism. This is a legitimate future evolution, not a hypothetical dismissed out of laziness — it's just explicitly out of scope per rule 1's "no multi-account" statement.
4. **Likely interviewer challenge:** "Doesn't `currency` conceptually belong to an account, not a person?" — Yes, in a domain that supports multiple accounts. Here, `Client` and "their one financial account" are the same aggregate root because rule 1 guarantees they always will be for this system's scope; splitting them without a behavioral need adds a join for zero benefit. A second likely challenge: "So you're storing a `currency` column that nothing reads?" — Correct, and that's a deliberate call: recording a stated domain fact costs one column, versus silently dropping a requirement, which would read as missing requirement coverage.
