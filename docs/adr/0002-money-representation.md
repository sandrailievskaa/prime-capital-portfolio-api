# ADR-002: Money Representation — brick/money over Decimal Columns

**Status:** Accepted   **Date:** 2026-09-04

## Context

Rule 5 mandates `decimal(15,2)` columns for `amount` and `price`, and a plain positive integer for `quantity` — "never a PHP float in any calculation path." Rule 15 adds a subtle but load-bearing fact: because `quantity` is always a whole integer, `quantity × price` can never produce more than 2 decimal places — there is no rounding decision to make anywhere in this system's arithmetic, ever. CLAUDE.md's rules of engagement separately state the client's explicit tooling preference: "Prefer `brick/money`... or BCMath with string-safe decimals as the fallback." This ADR has to decide not just *whether* to avoid floats (non-negotiable, already decided by rule 5) but *how* — which representation and library to use in the code that sits between the HTTP request and the `decimal(15,2)` columns.

## Decision

Use **`brick/money`** (`Brick\Money\Money`, backed by `brick/math`'s `BigDecimal`) as the in-code representation for every monetary value, at the boundary of every calculation and comparison. Money objects are constructed from the incoming request's decimal string (never a float), used for arithmetic and comparisons (`isGreaterThan`, `isLessThan`, `plus`, `minus`), and converted back to a fixed-scale decimal string only when binding to the `decimal(15,2)` column. `quantity` stays a plain PHP `int` — it is never wrapped in a Money object, since it is a count, not a monetary value.

## Devil's-Advocate Pass — Three Approaches

### Approach 1 (tempting, actually wrong): Integer cents (`amount_cents` bigint)

Storing money as integer minor units (cents) is the textbook answer to "how do you avoid floating-point money bugs" in almost every general fintech write-up — it sidesteps decimal representation entirely by making every value a plain integer. It is tempting precisely because it's the industry-default advice.

**Why it's wrong here specifically:** rule 5 is not phrased as "avoid floats" in the abstract — it explicitly mandates `decimal(15,2)` **columns**. Storing `amount_cents` as a bigint directly contradicts a stated non-negotiable rule, not just a style preference. It would also add a real conversion boundary (cents → decimal → cents) at every read/write for zero benefit, since rule 15 already guarantees the domain never produces a value needing more than 2 decimal places — the entire reason integer-cents exists (protecting against fractional-cent drift from repeated float operations) is a problem this domain structurally cannot have. Adopting it here would be solving a problem the requirements already solved, while breaking an explicit constraint.

### Approach 2: Raw BCMath (`bcadd`, `bcmul`, `bccomp` calls with decimal-string columns)

Use PHP's arbitrary-precision `bc*` functions directly against decimal strings pulled from Eloquent's `decimal` cast, with an explicit `$scale = 2` argument on every call.

**Pros:** zero extra dependency (BCMath is a bundled PHP extension, confirmed present in this environment), does exactly what's needed with no abstraction layer, and is explicitly sanctioned by CLAUDE.md as the fallback option.

**Cons:** every call site must remember to pass the scale argument correctly (PHP's `bcmath.scale` ini default varies by environment and is easy to get wrong silently — a missing scale argument doesn't error, it just silently truncates), there's no type system stopping a future contributor from writing `$a + $b` on two decimal strings instead of `bcadd($a, $b, 2)` — that mistake compiles, runs, and produces a subtly wrong number instead of an exception. It is correctness-equivalent to Approach 3 only if every call site is disciplined; nothing enforces that discipline.

### Approach 3 (chosen): `brick/money` value objects

**Pros:** `Money::of('100.00', 'USD')` (or a currency-less decimal wrapper via `BigDecimal` where currency isn't relevant) is immutable and throws on precision-losing operations rather than silently truncating — the exact failure mode Approach 2 is vulnerable to becomes a hard exception instead of a wrong balance. Arithmetic reads as domain operations (`$balance->minus($withdrawal)`) rather than function calls with a magic scale parameter repeated at every site. It's also the client's explicitly named preferred tool.

**Cons:** an added dependency and a small amount of conceptual overhead (constructing/deconstructing Money objects at the request and persistence boundaries) for a system whose money operations are, in truth, just add/subtract/compare and one multiply.

## Challenging the Recommendation

Before settling on Approach 3, it's worth asking directly: is a value-object money library overkill for a system that does four arithmetic operations total, when four disciplined `bcadd`/`bcsub`/`bccomp` calls would produce an identical result with one less dependency? A reviewer skeptical of "bringing in a library for four function calls" has a fair point in isolation.

The reason Approach 3 still wins here: the risk this system is specifically trying to eliminate (rule 5's entire reason for existing) is *a float silently entering a money calculation path*. BCMath's safety is entirely convention-based — it depends on every future call site remembering the scale argument and never writing `+`/`-`/`*` directly on a decimal string, with no compiler or type system catching the lapse. `brick/money`'s safety is structural: `Money` objects reject float construction and throw on precision loss instead of degrading silently. That structural guarantee directly reduces the likelihood of the exact bug class rule 5 exists to prevent, more reliably than a convention a future contributor has to remember. Combined with it being the client's stated preference, the marginal dependency cost is worth paying. The scope stays intentionally thin, though: this ADR does not adopt `brick/money`'s currency-conversion or multi-currency-aware features — just `Money`/`BigDecimal` as a safe arithmetic and comparison wrapper around values that are always already in the client's single currency.

## Consequences

- `composer.json` carries `brick/money` as a direct (non-dev) dependency — already added.
- `TransactionService` constructs `Money` from validated request input (decimal strings, never floats) and from ledger aggregates (cast to string before wrapping) — never from a raw float.
- `quantity` stays a native PHP `int`, validated as a positive integer at the FormRequest layer; it is never wrapped in `Money`.
- No `round()` call should ever appear near a monetary value anywhere in the codebase (rule 15) — if one is needed, that's a signal something upstream is wrong, not a place to add rounding.

---

### Answers to the standard question set

1. **What does the requirement force? What does it NOT require?** It forces `decimal(15,2)` storage and a complete ban on floats in calculation paths. It does not force a specific library — `brick/money` is a preference, not a hard rule — and it does not require any rounding strategy, since rule 15 makes that structurally unnecessary.
2. **Simplest vs. sophisticated:** BCMath (Approach 2) is the simpler valid solution; `brick/money` (Approach 3) is the more sophisticated one. The more sophisticated one is right here because the client explicitly named it, and because its type-safety reduces risk in exactly the area (accidental float/precision bugs) this assessment is implicitly testing for.
3. **At real scale:** with multi-currency support, real exchange rates, or asset classes needing more than 2 decimal places (e.g., crypto), `brick/money`'s `Currency`-aware objects become close to mandatory rather than a nice-to-have; raw BCMath calls scattered through the codebase would become unmanageable well before that point.
4. **Likely interviewer challenge:** "Why add a dependency for four arithmetic operations you could do with bundled BCMath?" — Answered above: the client asked for it, and its structural safety guarantees are a stronger defense against the specific bug class this system must avoid than a convention-based approach. A fair follow-up: "Doesn't this ADR use `brick/money` for more than it needs?" — No; the decision explicitly scopes it to arithmetic/comparison only, not currency conversion.
