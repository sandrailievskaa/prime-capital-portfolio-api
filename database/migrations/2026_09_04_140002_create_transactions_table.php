<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * No CHECK constraint enforcing amount XOR instrument_id/quantity/price —
 * see ADR-003's Amendment section for why (Blueprint has no fluent
 * multi-column CHECK, and SQLite can't add one after CREATE TABLE).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->string('type');
            $table->decimal('amount', 15, 2)->nullable();
            $table->foreignId('instrument_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity')->nullable();
            $table->decimal('price', 15, 2)->nullable();
            $table->decimal('transaction_fee', 15, 2)->default(0);
            $table->timestamp('created_at')->nullable();

            $table->index(['client_id', 'created_at']);
            $table->index(['client_id', 'instrument_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
