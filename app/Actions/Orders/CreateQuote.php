<?php

namespace App\Actions\Orders;

use App\Domain\Order\StateMachine;
use App\Enums\OrderEventType;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Order;
use App\Models\User;
use App\Settings\QuoteSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class CreateQuote
{
    public function __construct(
        private InsertOrderWithReference $insertOrder,
        private ValidateQuoteCustomer $customers,
        private QuoteSettings $settings,
        private RecalculateOrderPayments $payments,
        private RecordOrderEvent $events,
    ) {}

    public function handle(Customer $customer, User $by): Order
    {
        $customer = $this->customers->handle($customer->fresh());
        Gate::forUser($by)->authorize('create', Order::class);

        return DB::transaction(function () use ($customer, $by): Order {
            $customer = $this->customers->handle(Customer::query()->whereKey($customer->id)->sharedLock()->first());
            StateMachine::assert(null, OrderStatus::Quoted);
            $order = $this->insertOrder->handle('Q', [
                'source' => OrderSource::Quote, 'status' => OrderStatus::Quoted,
                'location_id' => Location::default()->id, 'price_tier' => $customer->price_tier,
                'customer_id' => $customer->id, 'customer_name' => $customer->name,
                'phone_e164' => $customer->phone_e164, 'email' => $customer->email,
                'created_by_id' => $by->id, 'locale' => config('app.locale', 'en'),
                'valid_until' => CarbonImmutable::now('Asia/Colombo')->addDays($this->settings->validity_days)->setTime(23, 59, 59)->setTimezone(config('app.timezone')),
            ]);
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $this->payments->handle($order);
            $this->events->handle($order, OrderEventType::Status, to: OrderStatus::Quoted, note: 'Quote created', by: $by);

            return $order->refresh()->load('lines');
        });
    }
}
