<?php

namespace App\Actions\Orders;

use App\Domain\Order\AmountTooLarge;
use App\Domain\Order\InvalidOrderTotals;
use App\Domain\Order\Totals;
use App\Enums\OrderLineKind;
use App\Models\Customer;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

final class PrepareCounterSaleLines
{
    public function __construct(private CounterSalePricing $pricing) {}

    /**
     * @param  list<CounterSaleLine>  $lines
     * @param  Collection<int, Variant>|null  $variants
     * @return list<array{kind: OrderLineKind, variant: Variant|null, description: string, qty: int, unitPriceCents: int, lineTotalCents: int}>
     */
    public function handle(Customer $customer, array $lines, ?Collection $variants = null): array
    {
        if ($lines === [] || count($lines) > 200) {
            throw ValidationException::withMessages(['lines' => 'Enter between 1 and 200 lines, including at least one product.']);
        }
        $ids = [];
        foreach ($lines as $line) {
            if ($line->kind === OrderLineKind::Product && $line->variantId !== null) {
                $ids[] = $line->variantId;
            }
        }
        if ($ids === []) {
            throw ValidationException::withMessages(['lines' => 'At least one product line is required.']);
        }
        $variants ??= Variant::query()->with('product')->whereIn('id', $ids)->get()->keyBy('id');
        $prepared = [];
        foreach ($lines as $line) {
            $variant = null;
            $price = $line->unitPriceCents;
            $description = trim($line->description ?? '');
            if ($line->kind === OrderLineKind::Product) {
                $variant = $variants->get($line->variantId);
                if ($variant === null || ! $variant->is_active) {
                    throw ValidationException::withMessages(['lines' => 'Choose an active variant for every product line.']);
                }
                $description = mb_substr($variant->displayName(), 0, 255);
                $price ??= $this->pricing->handle($variant, $customer->price_tier);
            } elseif ($line->variantId !== null || $description === '' || mb_strlen($description) > 255 || $price === null) {
                throw ValidationException::withMessages(['lines' => 'Delivery, labour, other and discount lines need a description (at most 255 characters), a unit price and no variant.']);
            }
            try {
                $total = Totals::lineTotal($line->kind, $line->qty, $price);
            } catch (InvalidOrderTotals|AmountTooLarge $exception) {
                throw ValidationException::withMessages(['lines' => $exception->getMessage()]);
            }
            $prepared[] = ['kind' => $line->kind, 'variant' => $variant, 'description' => $description, 'qty' => $line->qty, 'unitPriceCents' => $price, 'lineTotalCents' => $total];
        }

        return $prepared;
    }
}
