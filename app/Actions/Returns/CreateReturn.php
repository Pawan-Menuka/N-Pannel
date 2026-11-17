<?php

namespace App\Actions\Returns;

use App\Actions\Orders\RecalculateOrderPayments;
use App\Actions\Orders\RecordOrderEvent;
use App\Actions\Payments\RecordRefund;
use App\Actions\Stock\ReleaseStock;
use App\Domain\Money\Lkr;
use App\Domain\Returns\ReturnCalculator;
use App\Domain\Stock\StockLimitExceeded;
use App\Enums\OrderEventType;
use App\Enums\OrderLineKind;
use App\Enums\PaymentMethod;
use App\Enums\StockReason;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\SalesReturn;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** Lock order: customer (shared) -> order -> document sequence -> stock ascending variant_id. */
final class CreateReturn
{
    public function __construct(
        private IssueCreditNote $creditNotes,
        private ReleaseStock $stock,
        private RecalculateOrderPayments $payments,
        private RecordRefund $refunds,
        private RecordOrderEvent $events,
    ) {}

    /** @return Collection<int, OrderLine> */
    public function productLines(Order $order): Collection
    {
        return $order->lines()->where('kind', OrderLineKind::Product)
            ->withSum('returnLines as already_returned_qty', 'qty')->orderBy('sort_order')->orderBy('id')->get();
    }

    public function defaultUnitValue(Order $order, OrderLine $line): int
    {
        return ReturnCalculator::discountedUnitValue($line->unit_price_cents, $order->subtotal_cents, $order->discount_cents);
    }

    public function canRecord(Order $order, User $by): bool
    {
        return $by->can('create', SalesReturn::class) && ReturnCalculator::acceptsStatus($order->status)
            && $this->productLines($order)->contains(fn (OrderLine $line): bool => $line->qty > (int) $line->getAttribute('already_returned_qty'));
    }

    /**
     * @param  list<ReturnLineInput>  $lines
     * @return array{lines: list<array{order_line_id: int, qty: int, unit_value_cents: int, restock: bool, line_total_cents: int}>, total_cents: int, max_refundable_cents: int, errors: list<string>}
     */
    public function preview(Order $order, array $lines): array
    {
        return ReturnCalculator::calculate(
            $this->productLines($order)->map(fn (OrderLine $line): array => [
                'id' => $line->id, 'qty' => $line->qty, 'unit_price_cents' => $line->unit_price_cents,
                'already_returned_qty' => (int) $line->getAttribute('already_returned_qty'),
            ])->all(),
            array_map(fn (ReturnLineInput $line): array => [
                'order_line_id' => $line->orderLineId, 'qty' => $line->qty,
                'unit_value_cents' => $line->unitValueCents, 'restock' => $line->restock,
            ], $lines),
            $order->total_cents, $order->returned_cents, $this->payments->totals($order)['paid_cents'], $order->subtotal_cents, $order->discount_cents,
        );
    }

    /** @param list<ReturnLineInput> $lines */
    public function handle(Order $order, array $lines, string $reason, ?string $note, ?ReturnRefundInput $refund, User $by): SalesReturn
    {
        $order = Order::query()->findOrFail($order->id);
        $reason = trim($reason);
        $note = trim($note ?? '');
        $this->validate($order, $lines, $reason, $note, $refund);
        Gate::forUser($by)->authorize('create', SalesReturn::class);

        return DB::transaction(function () use ($order, $lines, $reason, $note, $refund, $by): SalesReturn {
            $customer = $order->customer_id === null ? null : Customer::query()->whereKey($order->customer_id)->sharedLock()->first();
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($locked->customer_id !== $order->customer_id || ($locked->customer_id !== null && $customer === null)) {
                throw ValidationException::withMessages(['customer_id' => 'The order customer has changed. Reload the order and try again.']);
            }
            $this->validate($locked, $lines, $reason, $note, $refund);
            $calculated = $this->preview($locked, $lines);
            $productLines = $this->productLines($locked)->keyBy('id');
            $return = $locked->salesReturns()->create([
                'reason' => $reason, 'note' => $note === '' ? null : $note,
                'total_cents' => $calculated['total_cents'], 'refund_cents' => $refund->amountCents ?? 0,
                'created_by_id' => $by->id,
            ]);
            $restock = [];
            $qty = 0;
            foreach ($calculated['lines'] as $input) {
                $line = $productLines->get($input['order_line_id']);
                if ($line === null) {
                    throw ValidationException::withMessages(['lines' => 'The order lines have changed. Reload the order and try again.']);
                }
                $return->lines()->create([
                    'order_line_id' => $line->id, 'variant_id' => $line->variant_id, 'qty' => $input['qty'],
                    'restock' => $input['restock'], 'unit_price_cents' => $input['unit_value_cents'], 'line_total_cents' => $input['line_total_cents'],
                ]);
                $qty += $input['qty'];
                if ($input['restock'] && $line->variant_id !== null) {
                    $restock[$line->variant_id] = ($restock[$line->variant_id] ?? 0) + $input['qty'];
                }
            }
            $locked->update(['returned_cents' => $locked->returned_cents + $return->total_cents]);
            $invoice = $locked->activeInvoice();
            $credit = $invoice === null ? null : $this->creditNotes->handle($invoice, $return, $by);
            ksort($restock, SORT_NUMERIC);
            try {
                foreach ($restock as $variantId => $quantity) {
                    $variant = Variant::query()->find($variantId);
                    if ($variant === null || ! $variant->track_stock) {
                        continue;
                    }
                    $location = Location::query()->findOrFail($locked->location_id);
                    while ($quantity > 0) {
                        $chunk = min($quantity, 1000000);
                        $this->stock->handle($variant, $chunk, StockReason::ReturnRestock, $return, $by, location: $location);
                        $quantity -= $chunk;
                    }
                }
            } catch (StockLimitExceeded $exception) {
                throw ValidationException::withMessages(['stock' => $exception->getMessage()]);
            }
            $this->payments->handle($locked);
            if ($refund !== null) {
                $this->refunds->handle($locked, $refund->method, $refund->amountCents, $reason, $by, $return);
            }
            $summary = 'Return of '.$qty.' items, '.Lkr::formatWithCurrency($return->total_cents)
                .($credit === null ? '' : ', credit note '.$credit->number)
                .($refund === null ? '' : ', refund '.Lkr::formatWithCurrency($refund->amountCents)).': '.$reason;
            $this->events->handle($locked, OrderEventType::Note, note: mb_substr($summary, 0, 255), by: $by);

            return $return->load(['lines.orderLine', 'creditNote', 'refund']);
        });
    }

    /** @param list<ReturnLineInput> $lines */
    private function validate(Order $order, array $lines, string $reason, string $note, ?ReturnRefundInput $refund): void
    {
        if (! ReturnCalculator::acceptsStatus($order->status)) {
            throw ValidationException::withMessages(['status' => 'Only an accepted order can have a return.']);
        }
        if ($reason === '' || mb_strlen($reason) > 255) {
            throw ValidationException::withMessages(['reason' => 'A return reason of at most 255 characters is required.']);
        }
        if (mb_strlen($note) > 2000) {
            throw ValidationException::withMessages(['note' => 'The return note must be at most 2,000 characters.']);
        }
        $calculated = $this->preview($order, $lines);
        if ($calculated['errors'] !== []) {
            throw ValidationException::withMessages(['lines' => $calculated['errors']]);
        }
        if ($refund !== null) {
            if ($refund->method === PaymentMethod::Payhere) {
                throw ValidationException::withMessages(['method' => 'PayHere refunds cannot be recorded by staff.']);
            }
            if ($refund->amountCents < 1 || $refund->amountCents > $calculated['max_refundable_cents']) {
                throw ValidationException::withMessages(['amount_cents' => 'Up to '.Lkr::formatWithCurrency($calculated['max_refundable_cents']).' can be refunded.']);
            }
        }
    }
}
