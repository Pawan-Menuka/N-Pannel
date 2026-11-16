<?php

namespace Database\Factories;

use App\Enums\DocumentKind;
use App\Models\DocumentSequence;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DocumentSequence> */
class DocumentSequenceFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'kind' => DocumentKind::Invoice,
            'year' => fake()->unique()->numberBetween(2020, 2100),
            'next_number' => 1,
        ];
    }
}
