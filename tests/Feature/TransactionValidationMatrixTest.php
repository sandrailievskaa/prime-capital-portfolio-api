<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesFixtures;
use Tests\TestCase;

class TransactionValidationMatrixTest extends TestCase
{
    use RefreshDatabase, CreatesFixtures;

    /**
     * CLAUDE.md regression seed (Phase 3): the full decimal-precision
     * matrix, exercised through the real HTTP endpoint this time instead
     * of a direct FormRequest call. Only a canonical decimal string
     * ("10.50", "010.50") passes — everything else, including values that
     * "look correct" as bare JSON numbers, must be rejected.
     *
     * Two Phase 3 conclusions changed here, and deliberately: leading and
     * trailing whitespace were found to fail validation when Phase 3
     * called the validator directly — but Laravel's global TrimStrings
     * middleware normalizes both to "10.50" before this request ever
     * reaches CreateTransactionRequest, so through the real HTTP pipeline
     * they correctly succeed. This is exactly the gap between testing a
     * component in isolation and testing the real boundary.
     *
     * @return array<string, array{0: mixed, 1: bool}>
     */
    public static function amountMatrixProvider(): array
    {
        return [
            'bare int 10' => [10, false],
            'bare float 10.5' => [10.5, false],
            'bare float 10.50 (collapses to 10.5)' => [10.50, false],
            'bare float 10.01' => [10.01, false],
            'bare float 10.123 (3 decimals)' => [10.123, false],
            'bare int 0 (not positive)' => [0, false],
            'bare int -1 (negative)' => [-1, false],
            'string "10.50" (canonical, valid)' => ['10.50', true],
            'string "abc" (not numeric)' => ['abc', false],
            'null' => [null, false],
            // Laravel's global TrimStrings middleware normalizes these to
            // "10.50" before CreateTransactionRequest ever sees them — a
            // real discovery from testing at the actual HTTP boundary
            // instead of calling the validator in isolation (Phase 3's
            // approach). The regex itself would reject raw whitespace if
            // it ever reached it uncleaned; through the real request
            // pipeline, it never does, so these correctly succeed.
            'leading whitespace " 10.50" (trimmed by middleware first)' => [' 10.50', true],
            'trailing whitespace "10.50 " (trimmed by middleware first)' => ['10.50 ', true],
            'leading plus "+10.50"' => ['+10.50', false],
            'leading zero "010.50" (valid, deliberately allowed)' => ['010.50', true],
            'empty string ""' => ['', false],
            'no leading digit ".50"' => ['.50', false],
        ];
    }

    #[DataProvider('amountMatrixProvider')]
    public function test_amount_decimal_precision_matrix(mixed $amount, bool $shouldPass): void
    {
        $client = $this->createClient();

        $payload = ['type' => 'deposit'];
        if ($amount !== null) {
            $payload['amount'] = $amount;
        } else {
            $payload['amount'] = null;
        }

        $response = $this->postJson("/api/clients/{$client->id}/transactions", $payload);

        if ($shouldPass) {
            $response->assertStatus(201);
        } else {
            $response->assertStatus(422);
        }
    }

    public function test_unknown_instrument_id_is_rejected_with_machine_readable_code(): void
    {
        $client = $this->createClient();

        $this->postJson("/api/clients/{$client->id}/transactions", [
            'type' => 'buy',
            'instrument_id' => 999999,
            'quantity' => 1,
            'price' => '10.00',
        ])->assertStatus(422)
            ->assertJsonPath('error_code', 'unknown_instrument');
    }

    public function test_unknown_top_level_field_is_rejected(): void
    {
        $client = $this->createClient();

        $this->postJson("/api/clients/{$client->id}/transactions", [
            'type' => 'deposit',
            'amount' => '10.00',
            'notes' => 'not a real field',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('notes');
    }
}
