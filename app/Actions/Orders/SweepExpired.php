<?php

namespace App\Actions\Orders;

use App\Domain\Order\QuoteValidity;
use App\Domain\Order\StateMachine;
use App\Enums\OrderEventType;
use App\Enums\OrderStatus;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Expires quotes only. Placed orders and their online checkout holds belong to Release 3. */
final class SweepExpired
{
    public function __construct(private RecordOrderEvent $events) {}

    public function handle(?CarbonImmutable $now = null): SweepResult
    {
        $now = ($now ?? CarbonImmutable::now())->setTimezone(config('app.timezone'));
        $expired = 0;
        foreach (Order::query()->where('status', OrderStatus::Quoted)->where('valid_until', '<', $now->format('Y-m-d H:i:s'))->lazyById(100) as $candidate) {
            $expired += DB::transaction(function () use ($candidate, $now): int {
                $quote = Order::query()->lockForUpdate()->find($candidate->id);
                if ($quote === null || $quote->status !== OrderStatus::Quoted || ! QuoteValidity::isExpired($now->getTimestamp(), $quote->valid_until?->getTimestamp())) {
                    return 0;
                }
                StateMachine::assert(OrderStatus::Quoted, OrderStatus::Expired);
                $quote->update(['status' => OrderStatus::Expired]);
                $date = $quote->valid_until?->copy()->setTimezone('Asia/Colombo')->format('d M Y');
                $this->events->handle($quote, OrderEventType::Status, from: OrderStatus::Quoted, to: OrderStatus::Expired, note: 'Quote expired (valid until '.$date.')');

                return 1;
            });
        }

        return new SweepResult($expired);
    }
}
