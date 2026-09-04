---
paths:
  - 'database/migrations/**'
---

# Migrations

## Use pure Blueprint/Schema::create() syntax — no raw SQL
Every migration is written with Laravel's Schema builder only, no raw DB::statement(). The `transactions.type` column is a plain `$table->string('type')`, not `$table->enum()` — the enum values are enforced at the application layer only (Transaction model casts it to the TransactionType backed enum; StoreTransactionRequest validates it via Rule::enum()), not via a DB-level CHECK. This is deliberate: a DB-native/enum-backed CHECK column is inflexible (adding a new transaction type later means an ALTER, not just a PHP enum case), and the app layer already fully owns this validation.

The multi-column XOR constraint this table used to enforce at the DB level (amount set only for deposit/withdrawal; instrument_id/quantity/price set only for buy/sell — ADR-003) was also dropped rather than kept via raw SQL: this Laravel version's Blueprint has no fluent multi-column CHECK method, and SQLite has no ALTER TABLE ADD CONSTRAINT to add one after creation. See ADR-003's "Amendment" section for the full trade-off — the FormRequest/controller layer already enforces the same XOR shape at the validation layer, so this is a defense-in-depth layer removed, not a real enforcement gap under current scope.
