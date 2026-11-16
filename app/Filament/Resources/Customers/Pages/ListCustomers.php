<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Actions\Customers\ResolveCustomer;
use App\Enums\CustomerType;
use App\Enums\PriceTier;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Support\PurchasingValidation;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Validation\ValidationException;

class ListCustomers extends ListRecords
{
    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            Action::make('findOrAdd')->label('Find or add by phone')->schema([
                TextInput::make('phone_e164')->label('Phone')->tel()->required(),
                TextInput::make('name')->required()->maxLength(255),
                Select::make('type')->options(CustomerType::class)->required()->default(CustomerType::Homeowner->value),
                Select::make('price_tier')->options(PriceTier::class)->required()->default(PriceTier::Retail->value),
            ])->action(function (array $data): void {
                /** @var User $actor */
                $actor = Filament::auth()->user();
                try {
                    $result = app(ResolveCustomer::class)->handle($data['phone_e164'], $data['name'], $actor, $data);
                } catch (ValidationException $exception) {
                    throw PurchasingValidation::forForm($exception, $this->getMountedActionSchema()?->getStatePath() ?? 'mountedActions.0.data');
                }
                $name = $result->customer->anonymised_at === null ? $result->customer->name : 'Removed customer';
                Notification::make()->success()->title(($result->created ? 'Added ' : 'Found ').e($name))->send();
                $this->redirect(CustomerResource::getUrl('view', ['record' => $result->customer]));
            }),
        ];
    }
}
