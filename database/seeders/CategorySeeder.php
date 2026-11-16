<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            'roofing' => 'Roofing',
            'ceilings' => 'Ceilings',
            'wall-panels' => 'Wall panels',
            'flooring' => 'Flooring',
            'accessories' => 'Accessories',
        ];

        foreach ($categories as $slug => $name) {
            Category::firstOrCreate(['slug' => $slug], [
                'name' => ['en' => $name, 'si' => $name, 'ta' => $name],
                'is_active' => in_array($slug, ['ceilings', 'accessories'], true),
            ]);
        }
    }
}
