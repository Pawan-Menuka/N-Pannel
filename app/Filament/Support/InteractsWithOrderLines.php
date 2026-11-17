<?php

namespace App\Filament\Support;

use App\Actions\Orders\OrderLineInput;
use App\Actions\Orders\OrderLinePricing;
use App\Actions\Orders\PrepareOrderLines;
use App\Domain\Money\InvalidAmount;
use App\Domain\Money\Lkr;
use App\Domain\Order\AmountTooLarge;
use App\Domain\Order\InvalidOrderTotals;
use App\Domain\Order\OrderTotals;
use App\Domain\Order\Totals;
use App\Enums\OrderLineKind;
use App\Enums\PriceTier;
use App\Models\Customer;
use App\Models\Location;
use App\Models\StockLevel;
use App\Models\Variant;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

trait InteractsWithOrderLines
{
    private ?Location $cachedLocation = null;

    /** @var Collection<int, Variant>|null */
    private ?Collection $cachedVariants = null;

    /** @var array<int, true> */
    private array $loadedVariantIds = [];

    private bool $previewLoaded = false;

    private ?OrderTotals $cachedPreview = null;

    abstract protected function selectedCustomer(): ?Customer;

    abstract protected function currentTier(): PriceTier;

    protected function allowsEmptyOrderLines(): bool
    {
        return false;
    }

    private function orderLinesRepeater(): Repeater
    {
        return OrderLinesRepeater::make(
            $this->currentTier(...), $this->variantFromState(...), $this->searchVariants(...),
            $this->variantLabel(...), $this->lineTotal(...), $this->orderLinesChanged(...), $this->allowsEmptyOrderLines(),
        );
    }

    protected function orderLinesChanged(): void
    {
        $this->resetPreview();
    }

    private function orderTotalsEntry(): TextEntry
    {
        return OrderLinesRepeater::totals($this->totalsLabel(...));
    }

    private function resetOrderLineCaches(): void
    {
        $this->cachedLocation = null;
        $this->cachedVariants = null;
        $this->loadedVariantIds = [];
        $this->resetPreview();
    }

    private function repriceOrderLines(PriceTier $oldTier, PriceTier $newTier): void
    {
        foreach ($this->data['lines'] ?? [] as $key => $line) {
            if ($this->lineKind($line['kind'] ?? null) !== OrderLineKind::Product) {
                continue;
            }
            $variant = $this->variantFromState($line['variant_id'] ?? null);
            $price = $line['unit_price'] ?? null;
            if ($variant === null || $price === null || $price === '') {
                continue;
            }
            try {
                $replacement = app(OrderLinePricing::class)->replacementPrice($variant, Lkr::parse((string) $price), $oldTier, $newTier);
            } catch (InvalidAmount) {
                continue;
            }
            if ($replacement !== null) {
                $this->data['lines'][$key]['unit_price'] = $this->rupees($replacement);
            }
        }
    }

    /** Numeric HTML input without Filament's NumberStateCast, which converts decimals to floats. */
    private function rupeeInput(string $name): TextInput
    {
        return OrderLineForm::rupeeInput($name);
    }

    private function rupees(int $cents): string
    {
        return OrderLineForm::rupees($cents);
    }

    private function parseMoney(string $value, string $field): int
    {
        return OrderLineForm::parseMoney($value, $field);
    }

    private function lineKind(mixed $state): ?OrderLineKind
    {
        if ($state instanceof OrderLineKind) {
            return $state;
        }

        return is_string($state) ? OrderLineKind::tryFrom($state) : null;
    }

    /**
     * @param  array<array-key, array<string, mixed>>  $state
     * @return list<OrderLineInput>
     */
    private function linesFromState(array $state): array
    {
        $lines = [];
        foreach ($state as $key => $line) {
            $kind = $this->lineKind($line['kind'] ?? null);
            if ($kind === null) {
                throw ValidationException::withMessages(['lines.'.$key.'.kind' => 'Choose a line kind.']);
            }
            $price = $line['unit_price'] ?? null;
            $lines[] = new OrderLineInput(
                $kind,
                isset($line['variant_id']) ? (int) $line['variant_id'] : null,
                $line['description'] ?? null,
                (int) ($line['qty'] ?? 0),
                $price === null || $price === '' ? null : $this->parseMoney((string) $price, 'lines.'.$key.'.unit_price'),
            );
        }

        return $lines;
    }

    /** @param list<OrderLineInput> $lines */
    private function calculateTotals(Customer $customer, array $lines): OrderTotals
    {
        $totalLines = [];
        foreach (app(PrepareOrderLines::class)->handle($customer, $lines, $this->variantsForLines(), allowEmpty: $this->allowsEmptyOrderLines()) as $line) {
            $totalLines[] = ['kind' => $line['kind'], 'qty' => $line['qty'], 'unitPriceCents' => $line['unitPriceCents']];
        }

        return Totals::calculate($totalLines);
    }

    private function previewTotals(): ?OrderTotals
    {
        if ($this->previewLoaded) {
            return $this->cachedPreview;
        }
        $this->previewLoaded = true;
        try {
            $this->cachedPreview = $this->calculateTotals($this->selectedCustomer() ?? new Customer, $this->linesFromState($this->data['lines'] ?? []));
        } catch (ValidationException|InvalidOrderTotals|AmountTooLarge) {
            $this->cachedPreview = null;
        }

        return $this->cachedPreview;
    }

    private function totalsLabel(): string
    {
        $totals = $this->previewTotals();
        if ($totals === null) {
            return '—';
        }

        return 'Subtotal: '.Lkr::formatWithCurrency($totals->subtotalCents)
            .' | Delivery: '.Lkr::formatWithCurrency($totals->deliveryCents)
            .' | Discount: '.Lkr::formatWithCurrency($totals->discountCents)
            .' | Total: '.Lkr::formatWithCurrency($totals->totalCents);
    }

    private function lineTotal(Get $get): string
    {
        try {
            $lines = $this->linesFromState([['kind' => $get('kind'), 'variant_id' => $get('variant_id'), 'description' => $get('description'), 'qty' => $get('qty'), 'unit_price' => $get('unit_price')]]);
            $line = $lines[0];
            $price = $line->unitPriceCents;
            if ($price === null && $line->variantId !== null) {
                $variant = $this->variantFromState($line->variantId);
                if ($variant !== null) {
                    $price = app(OrderLinePricing::class)->handle($variant, $this->currentTier());
                }
            }

            return $price === null ? '—' : Lkr::formatWithCurrency(Totals::lineTotal($line->kind, $line->qty, $price));
        } catch (ValidationException|InvalidOrderTotals|AmountTooLarge) {
            return '—';
        }
    }

    private function resetPreview(): void
    {
        $this->previewLoaded = false;
        $this->cachedPreview = null;
    }

    private function defaultLocation(): Location
    {
        return $this->cachedLocation ??= Location::default();
    }

    /** @return Collection<int, Variant> */
    private function variantsForLines(?int $extraId = null): Collection
    {
        $ids = $extraId === null ? [] : [$extraId];
        foreach ($this->data['lines'] ?? [] as $line) {
            if ($this->lineKind($line['kind'] ?? null) === OrderLineKind::Product && isset($line['variant_id'])) {
                $ids[] = (int) $line['variant_id'];
            }
        }
        $missing = [];
        foreach (array_unique($ids) as $id) {
            if (! isset($this->loadedVariantIds[$id])) {
                $missing[] = $id;
                $this->loadedVariantIds[$id] = true;
            }
        }
        $this->cachedVariants ??= new Collection;
        if ($missing !== []) {
            foreach ($this->variantQuery()->whereIn('id', $missing)->get() as $variant) {
                $this->cachedVariants->put($variant->id, $variant);
            }
        }

        return $this->cachedVariants;
    }

    private function variantFromState(mixed $value): ?Variant
    {
        if (! is_int($value) && ! is_string($value)) {
            return null;
        }

        return $this->variantsForLines((int) $value)->get((int) $value);
    }

    /** @return Builder<Variant> */
    private function variantQuery(): Builder
    {
        return Variant::query()->with(['product', 'stockLevels' => $this->stockAtDefaultLocation(...)])
            ->where('is_active', true);
    }

    /** @param HasMany<StockLevel, Variant> $query */
    private function stockAtDefaultLocation(HasMany $query): void
    {
        $query->where('location_id', $this->defaultLocation()->id);
    }

    /** @return array<int, string> */
    private function searchVariants(string $search): array
    {
        $pattern = '%'.addcslashes(trim($search), '\\%_').'%';

        return $this->variantQuery()->where(fn (Builder $query): Builder => $query->where('sku', 'ilike', $pattern)
            ->orWhereHas('product', fn (Builder $query): Builder => $query->where('title->en', 'ilike', $pattern)))
            ->orderBy('sku')->limit(50)->get()->mapWithKeys(fn (Variant $variant): array => [$variant->id => $this->variantLabel($variant)])->all();
    }

    private function variantLabel(Variant $variant): string
    {
        $level = $variant->stockLevels->first();
        $qty = $level === null ? 0 : $level->qty;

        return $variant->sku.' — '.$variant->displayName().' ('.($variant->track_stock ? 'in stock '.$qty : 'not tracked').')';
    }
}
