<?php

namespace Database\Seeders;

use App\Models\Client;
use Illuminate\Database\Seeder;

class ClientSeeder extends Seeder
{
    public function run(): void
    {
        Client::create(['name' => 'Ana', 'currency' => 'EUR']);
        Client::create(['name' => 'Marko', 'currency' => 'USD']);
        Client::create(['name' => 'Elena', 'currency' => 'USD']);
    }
}
