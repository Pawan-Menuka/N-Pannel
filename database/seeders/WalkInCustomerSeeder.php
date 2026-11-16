<?php

namespace Database\Seeders;

use App\Enums\CustomerType;
use App\Models\Customer;
use Illuminate\Database\Seeder;

class WalkInCustomerSeeder extends Seeder
{
    public function run(): void
    {
        Customer::firstOrCreate(['is_walk_in' => true], [
            'name' => 'Walk-in customer',
            'phone_e164' => null,
            'type' => CustomerType::Homeowner,
        ]);
    }
}
