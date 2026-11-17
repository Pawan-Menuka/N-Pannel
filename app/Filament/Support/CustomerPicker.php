<?php

namespace App\Filament\Support;

use App\Actions\Customers\ResolveCustomer;
use App\Actions\Customers\SearchCustomers;
use App\Models\Customer;
use App\Models\User;
use Closure;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

final class CustomerPicker
{
    /** @param (Closure(mixed): ?Customer)|null $resolve */
    public static function make(string $name = 'customer_id', ?Closure $resolve = null): Select
    {
        return Select::make($name)->label('Customer')->searchable()->live()
            ->getSearchResultsUsing(fn (string $search): array => app(SearchCustomers::class)->handle(self::query(), $search)->orderBy('name')->limit(50)->get()
                ->mapWithKeys(fn (Customer $customer): array => [$customer->id => self::label($customer)])->all())
            ->getOptionLabelUsing(function (mixed $value) use ($resolve): ?string {
                $customer = $resolve !== null ? $resolve($value) : self::query()->find(is_int($value) || is_string($value) ? $value : null);

                return $customer instanceof Customer ? self::label($customer) : null;
            })
            ->createOptionForm([
                TextInput::make('phone_e164')->label('Phone')->tel()->required(),
                TextInput::make('name')->required()->maxLength(255),
            ])->createOptionUsing(function (array $data, Schema $schema): int {
                /** @var User $by */
                $by = Filament::auth()->user();
                try {
                    return app(ResolveCustomer::class)->handle($data['phone_e164'], $data['name'], $by)->customer->id;
                } catch (ValidationException $exception) {
                    throw PurchasingValidation::forForm($exception, $schema->getStatePath() ?? 'mountedActions.0.data');
                }
            });
    }

    /** @return Builder<Customer> */
    public static function query(): Builder
    {
        return Customer::query()->where('is_walk_in', false)->whereNull('merged_into_id')->whereNull('anonymised_at');
    }

    public static function label(Customer $customer): string
    {
        return $customer->name.' — '.($customer->phone_e164 ?? 'No phone');
    }
}
