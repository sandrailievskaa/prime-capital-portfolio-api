<?php

namespace Tests\Feature;

use App\Models\Instrument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesFixtures;
use Tests\TestCase;

class InstrumentCreationTest extends TestCase
{
    use CreatesFixtures, RefreshDatabase;

    public function test_creating_an_instrument_succeeds(): void
    {
        $this->postJson('/api/instruments', ['ticker' => 'AAPL'])
            ->assertStatus(201)
            ->assertJsonPath('data.ticker', 'AAPL');

        $this->assertDatabaseHas('instruments', ['ticker' => 'AAPL']);
    }

    public function test_creating_an_instrument_with_a_duplicate_ticker_is_rejected(): void
    {
        $this->createInstrument('AAPL');

        $this->postJson('/api/instruments', ['ticker' => 'AAPL'])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'validation_failed')
            ->assertJsonStructure(['error_code', 'message', 'errors']);

        $this->assertSame(1, Instrument::where('ticker', 'AAPL')->count());
    }

    public function test_creating_an_instrument_without_a_ticker_is_rejected(): void
    {
        $this->postJson('/api/instruments', [])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'validation_failed');
    }

    /**
     * A buy/sell must reference an instrument that already exists (rule 2)
     * — this confirms the endpoint added here is the actual path that
     * makes that possible, end to end.
     */
    public function test_an_instrument_created_via_the_api_can_immediately_be_bought(): void
    {
        $client = $this->createClient();
        $this->postJson("/api/clients/{$client->id}/transactions", [
            'type' => 'deposit', 'amount' => '1000.00',
        ])->assertStatus(201);

        $instrument = $this->postJson('/api/instruments', ['ticker' => 'TSLA'])
            ->assertStatus(201)
            ->json('data');

        $this->postJson("/api/clients/{$client->id}/transactions", [
            'type' => 'buy', 'instrument_id' => $instrument['id'], 'quantity' => 2, 'price' => '50.00',
        ])->assertStatus(201);
    }
}
