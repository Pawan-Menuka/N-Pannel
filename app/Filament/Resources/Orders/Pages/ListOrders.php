<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Actions\Orders\CreateQuote;
use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Support\CustomerPicker;
use App\Filament\Support\PurchasingValidation;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    protected static ?string $title = 'Quotes and orders';

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All'),
            'quotes' => Tab::make('Quotes')->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', OrderStatus::Quoted)),
            'accepted' => Tab::make('Accepted')->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', OrderStatus::Confirmed)),
            'in_progress' => Tab::make('In progress')->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('status', [OrderStatus::Processing, OrderStatus::ReadyForCollection, OrderStatus::Dispatched])),
            'done' => Tab::make('Done')->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('status', [OrderStatus::Completed, OrderStatus::Cancelled, OrderStatus::Expired])),
            'needs_attention' => Tab::make('Needs attention')->modifyQueryUsing(fn (Builder $query): Builder => $query->where('needs_attention', true))
                ->badge(fn (): int => Order::query()->where('needs_attention', true)->count()),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('newQuote')->label('New quote')->visible(fn (): bool => OrderResource::canCreate())
                ->schema([CustomerPicker::make()->required()])->action(function (array $data): void {
                    /** @var User $by */
                    $by = Filament::auth()->user();
                    try {
                        $customer = Customer::query()->find($data['customer_id']);
                        if ($customer === null) {
                            throw ValidationException::withMessages(['customer_id' => 'Choose a customer.']);
                        }
                        $quote = app(CreateQuote::class)->handle($customer, $by);
                    } catch (ValidationException $exception) {
                        throw PurchasingValidation::forForm($exception, 'mountedActions.0.data');
                    }
                    $this->redirect(OrderResource::getUrl('quote', ['record' => $quote]));
                }),
            Action::make('newSale')->label('New sale')->url(OrderResource::getUrl('new-sale'))
                ->visible(fn (): bool => OrderResource::canCreate()),
        ];
    }
}
