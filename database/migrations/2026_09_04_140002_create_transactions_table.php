<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * This table is built with a raw CREATE TABLE statement instead of the
 * Blueprint fluent builder for one reason: the ADR-003 CHECK constraint
 * (amount XOR instrument_id+quantity+price, depending on type) must be part
 * of the original CREATE TABLE. SQLite has no ALTER TABLE ADD CONSTRAINT for
 * CHECK — it can only be declared at creation time — and this Laravel
 * version's Blueprint has no fluent check() method at all (verified against
 * the installed framework source). Every other constraint below (columns,
 * foreign keys, indexes) matches exactly what Schema::create()/Blueprint
 * would have generated for SQLite; only the CHECK clauses required dropping
 * to raw SQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE transactions (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                client_id INTEGER NOT NULL,
                type VARCHAR NOT NULL CHECK (type IN ('deposit', 'withdrawal', 'buy', 'sell')),
                amount DECIMAL(15, 2) NULL,
                instrument_id INTEGER NULL,
                quantity INTEGER NULL,
                price DECIMAL(15, 2) NULL,
                transaction_fee DECIMAL(15, 2) NOT NULL DEFAULT 0,
                created_at DATETIME NULL,
                CONSTRAINT transactions_client_id_foreign
                    FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE RESTRICT,
                CONSTRAINT transactions_instrument_id_foreign
                    FOREIGN KEY (instrument_id) REFERENCES instruments (id) ON DELETE RESTRICT,
                -- ADR-003, decided (not optional): a deposit/withdrawal carries only
                -- `amount`; a buy/sell carries only instrument_id+quantity+price.
                -- Neither set may be partially populated, and the two sets are
                -- mutually exclusive per row.
                CONSTRAINT transactions_amount_xor_instrument_fields CHECK (
                    (
                        type IN ('deposit', 'withdrawal')
                        AND amount IS NOT NULL
                        AND instrument_id IS NULL AND quantity IS NULL AND price IS NULL
                    )
                    OR
                    (
                        type IN ('buy', 'sell')
                        AND amount IS NULL
                        AND instrument_id IS NOT NULL AND quantity IS NOT NULL AND price IS NOT NULL
                    )
                )
            )
        SQL);

        // client_id + created_at: every read-side balance/history query filters
        // by client_id and walks the ledger in chronological order (PortfolioService's
        // SUM() aggregates, and GET /clients/{client}/transactions' paginated,
        // ORDER BY created_at history). A composite index lets the DB satisfy
        // "WHERE client_id = ? ORDER BY created_at" directly from the index,
        // without a separate sort step.
        DB::statement('CREATE INDEX transactions_client_id_created_at_index ON transactions (client_id, created_at)');

        // client_id + instrument_id: holdings aggregation (rule 10 — an instrument
        // at exactly zero held quantity must not appear at all) groups buy/sell rows
        // by (client_id, instrument_id) to sum signed quantity per instrument. This
        // composite index lets that grouping query filter and group directly from
        // the index instead of scanning every transaction row for the client.
        DB::statement('CREATE INDEX transactions_client_id_instrument_id_index ON transactions (client_id, instrument_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
