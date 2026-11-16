<?php

namespace App\Filament\Resources\Customers;

use App\Actions\Customers\SearchCustomers;
use App\Domain\Money\Lkr;
use App\Enums\CustomerType;
use App\Enums\PriceTier;
use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Resources\Customers\RelationManagers\OrdersRelationManager;
use App\Filament\Resources\Customers\RelationManagers\PaymentsRelationManager;
use App\Filament\Support\CustomerDeleteAction;
use App\Models\Customer;
use App\Models\District;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** @extends resource<Customer> */
class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?string $recordTitleAttribute = 'name';

    public static function getRecordTitle(?Model $record): ?string
    {
        if (! $record instanceof Customer) {
            return null;
        }

        return $record->anonymised_at === null ? $record->name : 'Removed customer';
    }

    /** @return Builder<Customer> */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereNull('merged_into_id')->withBalance();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('phone_e164')->label('Phone')->tel(),
            Select::make('type')->options(CustomerType::class)->required()->default(CustomerType::Homeowner->value),
            Select::make('price_tier')->options(PriceTier::class)->required()->default(PriceTier::Retail->value),
            TextInput::make('company_name')->maxLength(255),
            TextInput::make('vat_number')->label('VAT number')->maxLength(255),
            TextInput::make('email')->email()->maxLength(255),
            Select::make('district_id')->label('District')->searchable()->options(fn (): array => District::query()->orderBy('sort_order')->get()->mapWithKeys(fn (District $district): array => [$district->id => $district->name])->all()),
            TextInput::make('address_line1')->label('Address line 1')->maxLength(255),
            TextInput::make('address_line2')->label('Address line 2')->maxLength(255),
            TextInput::make('city')->maxLength(255),
            Textarea::make('notes')->maxLength(2000),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('name')->state(fn (Customer $record): string => $record->anonymised_at === null ? $record->name : 'Removed customer'),
            TextEntry::make('is_walk_in')->label('Customer')->state(fn (Customer $record): ?string => $record->is_walk_in ? 'Walk-in' : null)->badge()
                ->visible(fn (Customer $record): bool => $record->is_walk_in),
            TextEntry::make('phone_e164')->label('Phone'),
            TextEntry::make('type')->badge(), TextEntry::make('price_tier')->badge(),
            TextEntry::make('company_name'), TextEntry::make('vat_number')->label('VAT number'),
            TextEntry::make('email'), TextEntry::make('district.name')->label('District'),
            TextEntry::make('address_line1')->label('Address line 1'), TextEntry::make('address_line2')->label('Address line 2'),
            TextEntry::make('city'), TextEntry::make('notes'),
            TextEntry::make('balance_cents')->label('Balance owed')->formatStateUsing(fn (int $state): string => Lkr::formatWithCurrency($state)),
            TextEntry::make('last_activity_at')->label('Last activity')->since(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('last_activity_at', 'desc')->searchable()
            ->searchUsing(fn (Builder $query, string $search): Builder => app(SearchCustomers::class)->handle($query, $search))
            ->columns([
                TextColumn::make('name')->state(fn (Customer $record): string => $record->anonymised_at === null ? $record->name : 'Removed customer')
                    ->description(fn (Customer $record): ?string => $record->company_name)->sortable(),
                TextColumn::make('is_walk_in')->label('Customer')->state(fn (Customer $record): ?string => $record->is_walk_in ? 'Walk-in' : null)->badge(),
                TextColumn::make('phone_e164')->label('Phone'),
                TextColumn::make('type')->badge(), TextColumn::make('price_tier')->badge(),
                TextColumn::make('balance_cents')->label('Balance owed')->formatStateUsing(fn (int $state): string => Lkr::formatWithCurrency($state))->sortable(),
                TextColumn::make('last_activity_at')->label('Last activity')->since(),
            ])->filters([
                SelectFilter::make('type')->options(CustomerType::class),
                SelectFilter::make('price_tier')->options(PriceTier::class),
                Filter::make('owes_money')->label('Owes money')->query(self::applyOwingMoneyFilter(...)),
            ])->recordActions([ViewAction::make(), EditAction::make(), CustomerDeleteAction::make()]);
    }

    /**
     * @param  Builder<Customer>  $query
     * @return Builder<Customer>
     */
    public static function applyOwingMoneyFilter(Builder $query): Builder
    {
        return $query->owingMoney();
    }

    public static function getRelations(): array
    {
        return [OrdersRelationManager::class, PaymentsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomers::route('/'), 'create' => CreateCustomer::route('/create'),
            'view' => ViewCustomer::route('/{record}'), 'edit' => EditCustomer::route('/{record}/edit'),
        ];
    }
}
