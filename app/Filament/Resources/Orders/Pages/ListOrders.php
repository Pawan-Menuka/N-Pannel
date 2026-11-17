<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Actions\Orders\CreateQuote;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Support\CustomerPicker;
use App\Filament\Support\PurchasingValidation;
use App\Models\Customer;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Validation\ValidationException;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    protected static ?string $title = 'Quotes and orders';

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
