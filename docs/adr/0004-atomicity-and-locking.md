# ADR-004: Atomicity Mechanism — DB::transaction() and a Defensive lockForUpdate()

**Status:** Accepted   **Date:** 2026-09-04

## Context

Rule 11 requires every write to be atomic: on any rule violation, zero rows are written and zero balances change. The write path itself is a single `INSERT` into `transactions`, which is already atomic at the storage-engine level on its own — so atomicity of the write statement itself is not actually in question. What precedes the write is a **read-decide-write** sequence: read the client's current derived cash balance and/or instrument holdings (an aggregate query over `transactions`), decide whether the requested operation is valid against rules 8 and 9 (no negative cash, no overselling), then insert. Rule 14 separately confirms true concurrent-request handling is out of scope for this assessment (client confirmed), but explicitly states rule 11 still holds regardless, and explicitly frames whether to add `lockForUpdate()` defensively as "a real trade-off worth an ADR, not an assumption either way" — with an instruction that if adopted, the lock target should be the parent `Client` row, since balance is a computed aggregate with no single lockable row of its own.

## Devil's-Advocate Pass — Three Approaches

### Approach 1 (tempting, actually wrong): No `DB::transaction()` wrapper at all

Reasoning that sounds plausible: "the only write is a single `INSERT`, and a single `INSERT` is already atomic at the storage-engine level — wrapping it in a transaction is ceremony around a statement that doesn't need it." This is tempting because it looks like avoiding premature engineering for a trivial write path.

**Why it's wrong:** the operation isn't just the `INSERT` — it's read-then-decide-then-write, and the `INSERT`'s own atomicity says nothing about whether the *read* it was based on is still valid by the time it lands. Rule 11 explicitly names `DB::transaction()` as "the correct habitual boundary around any 'read business state → decide → write' operation regardless of how many statements it happens to be today," specifically pre-empting the "it's just one statement" argument. Skipping it entirely also removes the scaffolding a defensive lock (Approach 3) would need to actually hold until commit — a `lockForUpdate()` call outside a transaction releases its lock immediately rather than holding it through the decision and the write, making it pointless. This is a real trap: it looks like avoiding over-engineering, but it actually ignores an explicit, precise instruction already given for exactly this situation.

### Approach 2: `DB::transaction()` around validate-then-insert, no lock

Wrap the read-decide-write sequence in a transaction (rollback-safety if anything after the read throws), but read the client's balance with a plain `SELECT`/aggregate query — no row lock.

**Pros:** correctly creates a rollback boundary; matches the "habitual boundary" framing from rule 11 exactly; costs nothing extra at write time beyond the transaction itself.

**Cons:** does not close the actual race. Under default read-committed-style isolation, a plain read inside a transaction doesn't block a concurrent connection from also reading the same (still-unmodified) balance and also deciding a conflicting write is valid — two simultaneous withdrawal requests could both read balance = 100, both validate a 60 withdrawal as fine, and both insert, leaving balance at -20. Since rule 14 confirms this scenario isn't exercised by this assessment's test surface, this gap would never actually be observed here — but it is a real, known gap, not a theoretical one.

### Approach 3 (chosen): `DB::transaction()` + `lockForUpdate()` on the parent `Client` row

Inside the transaction, resolve the client with `Client::lockForUpdate()->findOrFail($id)` before computing balance/holdings, then validate and insert. This serializes concurrent writers for the *same* client (a lock on one client's row does not block writes for any other client) so the read-decide-write sequence for one client can never interleave with another write for that same client.

**Pros:** closes the race that Approach 2 leaves open, with a one-line change riding on a transaction boundary that already has to exist per rule 11's own reasoning. Directly demonstrates the precise understanding CLAUDE.md asks for ("be ready to explain this precisely, not just say 'we use a DB transaction for atomicity'").

**Cons:** defends against a scenario (genuine concurrent requests) explicitly confirmed as out of scope for this assessment (rule 14) — real cost, even if small, for a case that will not be exercised or graded on concurrency behavior specifically.

## Challenging the Recommendation

Before settling on Approach 3, the honest counter-argument deserves a direct hearing: CLAUDE.md's rules of engagement repeatedly instruct against building for hypothetical scenarios — "Prefer the simplest design that satisfies every rule above... this is a scoped assessment, not a production system," and rule 16's instruction not to build shared machinery unless behavior genuinely warrants it. Rule 14 states plainly that concurrency is confirmed out of scope. A strict reading of "don't build what isn't required" says: skip the lock, ship Approach 2, and note the gap in this ADR as a known, accepted limitation.

The reason Approach 3 still wins: `lockForUpdate()` here is not a new abstraction, subsystem, or structural pattern — it is a one-line modification to a single query inside a transaction boundary that rule 11 already mandates exists. It doesn't introduce a queue, a caching layer, a repository pattern, or any of the specific things the rules of engagement warn against; it adds a lock clause to a `SELECT` that was already going to run. The marginal complexity is close to zero, while the correctness gap it closes is real (even if untested here) and directly on-topic for a system whose entire premise is "cash must never go negative" (rule 8) and "never oversell" (rule 9) — the exact invariants a lost-update race would violate. Given CLAUDE.md itself walks through, unprompted, precisely why `DB::transaction()` sets up a lock to "actually hold its lock until the insert commits," the document is signaling that reasoning through to the lock is the expected depth of analysis here, not that the lock must be skipped because concurrency isn't tested. This is a genuine judgment call, not a slam dunk — a reviewer applying strict YAGNI could reasonably choose Approach 2 instead, and that would not be an unreasonable position. This ADR chooses Approach 3 because the cost-to-benefit ratio of this specific one-line defensive measure is unusually favorable, not because "more defensive is always better."

## Consequences

- Every `TransactionService` write method (`deposit`, `withdraw`, `buy`, `sell`) opens with `DB::transaction(function () { ... })`, and the first statement inside is `Client::lockForUpdate()->findOrFail($clientId)`, not a plain `find()`.
- The lock target is always the `Client` row, never the `transactions` table and never an individual `Transaction` row — there is no single row representing "the balance" to lock, since it's a computed aggregate (per rule 7 and per CLAUDE.md's explicit guidance on this point).
- Read-only endpoints (`GET cash-balance`, `GET portfolio`, `GET transactions`) do **not** take a lock — locking is only relevant to the write path, where a decision is being made based on a read.

---

### Answers to the standard question set

1. **What does the requirement force? What does it NOT require?** Rule 11 forces a transaction boundary around any read-decide-write sequence, regardless of statement count. It explicitly does *not* force concurrent-request handling (rule 14) — `lockForUpdate()` is a deliberate choice layered on top of a mandatory boundary, not itself mandated.
2. **Simplest vs. sophisticated:** Approach 2 (transaction, no lock) is the simplest valid solution; Approach 3 (transaction + lock) is the more sophisticated one. The more sophisticated one is chosen here specifically because its marginal cost is unusually low (one line, no new abstraction) relative to the correctness property it protects (rules 8/9's core invariants), not as a general "always add locking" stance.
3. **At real scale:** with genuine concurrent traffic, `lockForUpdate()` on `Client` becomes not just defensible but necessary — without it there is a real, exploitable double-spend bug. At very high scale, row-level pessimistic locking on a "hot" client row (one being written to very frequently) can itself become a contention bottleneck; at that point the right evolution is optimistic concurrency (a `version`/`lock_version` column with compare-and-swap on write) or serializing writes per-client through a queue, rather than a blocking row lock.
4. **Likely interviewer challenge:** "You said concurrency is explicitly out of scope — isn't adding a lock exactly the speculative complexity CLAUDE.md tells you to avoid?" Answered directly above: it's a one-line addition to an already-mandatory transaction, not a new abstraction, and it's a genuine judgment call rather than an obviously correct one — a reasonable engineer could choose Approach 2 and defend it. A likely follow-up: "Why lock `Client` and not `transactions`?" — because balance and holdings are computed aggregates with no single row to lock; locking `Client` serializes writers for that one client without affecting unrelated rows or unrelated clients' inserts.
