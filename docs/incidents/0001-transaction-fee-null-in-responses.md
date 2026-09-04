# 0001: `transaction_fee` shown as `null` in every API response (Phases 4–6)

**What happened:** every successful `POST /transactions` response (deposit, withdrawal, buy, sell) showed `transaction_fee: null`, even though the actual database row correctly held `0` — undetected by 55+ passing automated tests across three phases, found only via adversarial QA in Phase 8.

**Root cause:** `Transaction::create()` returns the in-memory model instance built from the attributes passed to it; Eloquent does not re-fetch DB-computed column defaults after `INSERT`, so a field never explicitly set stays `null` in memory regardless of what the database actually stored.

**Fix:** all four `TransactionService` write methods (`deposit`, `withdraw`, `buy`, `sell`) now pass `'transaction_fee' => 0` explicitly in their `Transaction::create()` calls.

**Regression test:** `TransactionCreationTest::test_transaction_fee_shows_zero_not_null_in_the_response_and_matches_the_database` — asserts both the API response and a fresh database read. Verified it fails against the pre-fix code (temporarily reverted, confirmed failure, restored).
