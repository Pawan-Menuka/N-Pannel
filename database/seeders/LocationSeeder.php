<?php

namespace Database\Seeders;

use App\Models\Location;
use Illuminate\Database\Seeder;

class LocationSeeder extends Seeder
{
    public function run(): void
    {
        Location::firstOrCreate(['code' => 'main'], [
            'name' => 'Main',
            'is_default' => true,
            'is_active' => true,
        ]);
    }
}
