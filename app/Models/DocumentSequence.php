<?php

namespace App\Models;

use App\Enums\DocumentKind;
use Database\Factories\DocumentSequenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['kind', 'year', 'next_number'])]
class DocumentSequence extends Model
{
    /** @use HasFactory<DocumentSequenceFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'next_number' => 1,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => DocumentKind::class,
            'year' => 'integer',
            'next_number' => 'integer',
        ];
    }
}
