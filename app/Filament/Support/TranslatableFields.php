<?php

namespace App\Filament\Support;

use App\Models\Category;
use App\Models\Product;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Str;

final class TranslatableFields
{
    public static function make(string $attribute, string $label, bool $textarea = false, bool $requiredEnglish = true): Tabs
    {
        $tabs = [];
        foreach (['en' => 'English', 'si' => 'Sinhala', 'ta' => 'Tamil'] as $locale => $language) {
            $field = $textarea ? Textarea::make($attribute.'.'.$locale) : TextInput::make($attribute.'.'.$locale);
            $field->label($label)->required($locale === 'en' && $requiredEnglish);
            if (! $textarea) {
                $field->maxLength(255);
            }
            if ($locale === 'en' && in_array($attribute, ['name', 'title'], true)) {
                $field->live(onBlur: true)->afterStateUpdated(function (?string $state, Set $set, string $operation): void {
                    if ($operation === 'create') {
                        $set('slug', Str::slug($state ?? ''));
                    }
                });
            }
            $tabs[] = Tab::make($language)->schema([$field]);
        }

        return Tabs::make($label)->tabs($tabs)->columnSpanFull();
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function fill(Category|Product $record, array $data): array
    {
        foreach ($record->getTranslatableAttributes() as $attribute) {
            $data[$attribute] = $record->getTranslations($attribute);
        }

        return $data;
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function merge(Category|Product $record, array $data): array
    {
        foreach ($record->getTranslatableAttributes() as $attribute) {
            if (isset($data[$attribute]) && is_array($data[$attribute])) {
                $data[$attribute] = array_replace($record->getTranslations($attribute), $data[$attribute]);
            }
        }

        return $data;
    }
}
