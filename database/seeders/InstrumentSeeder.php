<?php

namespace Database\Seeders;

use App\Models\Instrument;
use Illuminate\Database\Seeder;

class InstrumentSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['AAPL', 'TSLA', 'MSFT'] as $ticker) {
            Instrument::create(['ticker' => $ticker]);
        }
    }
}
