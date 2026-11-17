<?php

namespace App\Filament\Support;

use App\Actions\Orders\OrderLinePricing;
use App\Enums\OrderLineKind;
use App\Enums\PriceTier;
use App\Models\Variant;
use Closure;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

final class OrderLinesRepeater
{
    /**
     * @param  Closure(): PriceTier  $tier
     * @param  Closure(mixed): ?Variant  $variant
     * @param  Closure(string): array<int, string>  $search
     * @param  Closure(Variant): string  $label
     * @param  Closure(Get): string  $lineTotal
     * @param  Closure(): void  $changed
     */
    public static function make(Closure $tier, Closure $variant, Closure $search, Closure $label, Closure $lineTotal, Closure $changed, bool $allowEmpty = false): Repeater
    {
        return Repeater::make('lines')->live()->minItems($allowEmpty ? 0 : 1)->maxItems(200)->defaultItems($allowEmpty ? 0 : 1)
            ->afterStateUpdated($changed)->schema([
                Select::make('kind')->options(OrderLineKind::class)->default(OrderLineKind::Product)->required()->live()
                    ->afterStateUpdated(function (Set $set): void {
                        $set('variant_id', null);
                        $set('description', null);
                        $set('unit_price', null);
                    }),
                Select::make('variant_id')->label('Item')->searchable()->live()
                    ->visible(fn (Get $get): bool => self::kind($get('kind')) === OrderLineKind::Product)
                    ->required(fn (Get $get): bool => self::kind($get('kind')) === OrderLineKind::Product)
                    ->getSearchResultsUsing($search)
                    ->getOptionLabelUsing(function (mixed $value) use ($variant, $label): ?string {
                        $item = $variant($value);

                        return $item instanceof Variant ? $label($item) : null;
                    })->afterStateUpdated(function (mixed $state, Set $set) use ($variant, $tier): void {
                        $item = $variant($state);
                        if ($item instanceof Variant) {
                            $set('unit_price', OrderLineForm::rupees(app(OrderLinePricing::class)->handle($item, $tier())));
                        }
                    }),
                TextInput::make('description')->maxLength(255)
                    ->visible(fn (Get $get): bool => self::kind($get('kind')) !== OrderLineKind::Product)
                    ->required(fn (Get $get): bool => self::kind($get('kind')) !== OrderLineKind::Product),
                TextInput::make('qty')->label('Qty')->integer()->default(1)->required()->minValue(1)->maxValue(1000000)->live(),
                OrderLineForm::rupeeInput('unit_price')->label('Unit price')->live()
                    ->required(fn (Get $get): bool => self::kind($get('kind')) !== OrderLineKind::Product),
                TextEntry::make('line_total')->label('Line total')->state($lineTotal),
            ]);
    }

    public static function kind(mixed $state): ?OrderLineKind
    {
        return $state instanceof OrderLineKind ? $state : (is_string($state) ? OrderLineKind::tryFrom($state) : null);
    }

    /** @param Closure(): string $label */
    public static function totals(Closure $label): TextEntry
    {
        return TextEntry::make('totals')->state($label);
    }
}
