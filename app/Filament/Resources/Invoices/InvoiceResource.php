<?php

namespace App\Filament\Resources\Invoices;

use App\Domain\Money\Lkr;
use App\Enums\DocumentStatus;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\CreditNote;
use App\Models\Invoice;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** @extends resource<Invoice> */
class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?string $navigationLabel = 'Invoices';

    protected static ?string $recordTitleAttribute = 'number';

    public static function table(Table $table): Table
    {
        return $table->defaultSort(fn (Builder $query): Builder => $query->orderByDesc('issued_on')->orderByDesc('id'))->columns([
            TextColumn::make('number')->searchable(), TextColumn::make('issued_on')->date(),
            TextColumn::make('customer_snapshot.name')->label('Customer')->searchable(query: fn (Builder $query, string $search): Builder => $query->where('customer_snapshot->name', 'ilike', '%'.$search.'%')),
            TextColumn::make('order.reference')->label('Order')->url(fn (Invoice $record): string => OrderResource::getUrl('view', ['record' => $record->order_id])),
            TextColumn::make('total_cents')->label('Total')->formatStateUsing(fn (int $state): string => Lkr::formatWithCurrency($state)),
            TextColumn::make('tax_cents')->label('Tax')->formatStateUsing(fn (int $state): string => Lkr::formatWithCurrency($state)),
            TextColumn::make('status')->badge(), TextColumn::make('due_on')->date(),
        ])->filters([
            SelectFilter::make('status')->options(DocumentStatus::class),
            SelectFilter::make('customer')->relationship('customer', 'name')->searchable()->preload(),
            Filter::make('issued_on')->schema([DatePicker::make('from'), DatePicker::make('until')->afterOrEqual('from')])
                ->query(fn (Builder $query, array $data): Builder => $query
                    ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('issued_on', '>=', $date))
                    ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('issued_on', '<=', $date))),
        ])->recordActions([ViewAction::make()]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Invoice')->schema([
                TextEntry::make('number'), TextEntry::make('status')->badge(), TextEntry::make('issued_on')->date(),
                TextEntry::make('due_on')->date()->visible(fn (TextEntry $component): bool => filled($component->getState())),
                TextEntry::make('order.reference')->label('Order')->url(fn (Invoice $record): string => OrderResource::getUrl('view', ['record' => $record->order_id])),
                TextEntry::make('seller_vat_number')->label('Seller VAT number')->visible(fn (TextEntry $component): bool => filled($component->getState())),
            ]),
            Section::make('Buyer')->schema([
                TextEntry::make('customer_snapshot.name')->label('Name'),
                TextEntry::make('customer_snapshot.company_name')->label('Company')->visible(fn (TextEntry $component): bool => filled($component->getState())),
                TextEntry::make('customer_snapshot.address_line1')->label('Address')->visible(fn (TextEntry $component): bool => filled($component->getState())),
                TextEntry::make('customer_snapshot.address_line2')->hiddenLabel()->visible(fn (TextEntry $component): bool => filled($component->getState())),
                TextEntry::make('customer_snapshot.city')->label('City')->visible(fn (TextEntry $component): bool => filled($component->getState())),
                TextEntry::make('customer_snapshot.phone_e164')->label('Phone')->visible(fn (TextEntry $component): bool => filled($component->getState())),
                TextEntry::make('customer_snapshot.email')->label('Email')->visible(fn (TextEntry $component): bool => filled($component->getState())),
                TextEntry::make('customer_snapshot.vat_number')->label('Buyer VAT number')->visible(fn (TextEntry $component): bool => filled($component->getState())),
            ]),
            RepeatableEntry::make('documentLines')->label('Lines')->state(fn (ViewInvoice $livewire): array => $livewire->document->lines)->schema([
                TextEntry::make('description'),
                TextEntry::make('sku')->label('SKU')->visible(fn (TextEntry $component): bool => filled($component->getState())),
                TextEntry::make('saleUnit')->label('Unit')->visible(fn (TextEntry $component): bool => filled($component->getState())),
                TextEntry::make('qty')->label('Qty'), TextEntry::make('unitPrice')->label('Unit price'), TextEntry::make('lineTotal')->label('Amount'),
            ]),
            Section::make('Totals')->schema([
                TextEntry::make('subtotal_cents')->label('Subtotal')->formatStateUsing(fn (int $state): string => Lkr::formatWithCurrency($state)),
                TextEntry::make('delivery_cents')->label('Delivery')->formatStateUsing(fn (int $state): string => Lkr::formatWithCurrency($state)),
                TextEntry::make('discount_cents')->label('Discount')->formatStateUsing(fn (int $state): string => Lkr::formatWithCurrency($state)),
                TextEntry::make('total_cents')->label('Total')->formatStateUsing(fn (int $state): string => Lkr::formatWithCurrency($state)),
                TextEntry::make('vatLine')->hiddenLabel()->state(fn (ViewInvoice $livewire): ?string => $livewire->document->vatLine)->visible(fn (Invoice $record): bool => $record->vat_rate_bp !== null),
                TextEntry::make('amountPaid')->label('Amount paid')->state(fn (ViewInvoice $livewire): string => $livewire->document->paid),
                TextEntry::make('balanceDue')->label('Balance due')->state(fn (ViewInvoice $livewire): string => $livewire->document->balance),
            ]),
            RepeatableEntry::make('documentPayments')->label('Payments')->state(fn (ViewInvoice $livewire): array => $livewire->document->payments)->schema([
                TextEntry::make('receiptNumber')->label('Receipt number')->visible(fn (TextEntry $component): bool => filled($component->getState())),
                TextEntry::make('date'), TextEntry::make('method'), TextEntry::make('amount'),
                TextEntry::make('receiptUrl')->label('Receipt')->formatStateUsing(fn (string $state): string => 'Print receipt')
                    ->url(fn (?string $state): ?string => $state)->openUrlInNewTab()->visible(fn (TextEntry $component): bool => filled($component->getState())),
            ]),
            Section::make('Credit notes')->visible(fn (Invoice $record): bool => $record->creditNotes()->exists())->schema([
                RepeatableEntry::make('creditNotes')->hiddenLabel()->schema([
                    TextEntry::make('number'), TextEntry::make('issued_on')->label('Date')->date(),
                    TextEntry::make('total_cents')->label('Total')->formatStateUsing(fn (int $state): string => Lkr::formatWithCurrency($state)),
                    TextEntry::make('printCreditNote')->label('Print')->state('Print credit note')
                        ->url(fn (CreditNote $record): string => route('filament.admin.credit-notes.print', ['creditNote' => $record]))->openUrlInNewTab(),
                ]),
            ]),
            Section::make('Void information')->visible(fn (Invoice $record): bool => $record->status === DocumentStatus::Void)->schema([
                TextEntry::make('voided_at')->dateTime()->visible(fn (TextEntry $component): bool => filled($component->getState())),
                TextEntry::make('void_reason')->visible(fn (TextEntry $component): bool => filled($component->getState())),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListInvoices::route('/'), 'view' => ViewInvoice::route('/{record}')];
    }
}
