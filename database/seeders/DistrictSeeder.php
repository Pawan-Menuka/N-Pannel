<?php

namespace Database\Seeders;

use App\Models\District;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DistrictSeeder extends Seeder
{
    public function run(): void
    {
        $provinces = [
            'Western' => ['Colombo', 'Gampaha', 'Kalutara'],
            'Central' => ['Kandy', 'Matale', 'Nuwara Eliya'],
            'Southern' => ['Galle', 'Matara', 'Hambantota'],
            'Northern' => ['Jaffna', 'Kilinochchi', 'Mannar', 'Vavuniya', 'Mullaitivu'],
            'Eastern' => ['Batticaloa', 'Ampara', 'Trincomalee'],
            'North Western' => ['Kurunegala', 'Puttalam'],
            'North Central' => ['Anuradhapura', 'Polonnaruwa'],
            'Uva' => ['Badulla', 'Monaragala'],
            'Sabaragamuwa' => ['Ratnapura', 'Kegalle'],
        ];

        $sortOrder = 0;

        foreach ($provinces as $province => $districts) {
            foreach ($districts as $name) {
                District::updateOrCreate(['slug' => Str::slug($name)], [
                    'name' => ['en' => $name, 'si' => $name, 'ta' => $name],
                    'province' => $province,
                    'sort_order' => $sortOrder++,
                ]);
            }
        }
    }
}
