<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instruments', function (Blueprint $table) {
            $table->id();
            // Free-text ticker (rule 2) — pre-seeded, never created implicitly by
            // buy/sell. Uniqueness is enforced here; "not empty" is a FormRequest
            // validation concern (Phase 3), not a schema-level constraint.
            $table->string('ticker')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instruments');
    }
};
