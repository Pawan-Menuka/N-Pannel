<?php

namespace App\Filament\Resources\Orders;

use App\Actions\Payments\CanPrintReceipt;
use App\Domain\Money\Lkr;
use App\Enums\LateCancelReason;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Orders\Pages\EditQuote;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\NewSale;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Orders\RelationManagers\QuoteSnapshotsRelationManager;
use App\Models\Order;
use App\Models\Payment;
use App\Models\SalesReturn;
use App\Models\SalesReturnLine;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** @extends resource<Order> */
class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?string $navigationLabel = 'Quotes and orders';

    protected static ?string $pluralModelLabel = 'Quotes and orders';

    protected static ?string $recordTitleAttribute = 'reference';

    public static function table(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')->columns([
            TextColumn::make('reference')->searchable(['reference', 'customer_name', 'phone_e164']),
            TextColumn::make('customer_name')->label('Customer'),
            TextColumn::make('source')->badge(),
            TextColumn::make('status')->badge(),
            IconColumn::make('needs_attention')->label('Needs attention')->boolean(),
            TextColumn::make('payment_state')->badge(),
            TextColumn::make('total_cents')->label('Total')->formatStateUsing(fn (int $state): string => Lkr::formatWithCurrency($state)),
            TextColumn::make('paid_cents')->label('Paid')->formatStateUsing(fn (int $state): string => Lkr::formatWithCurrency($state)),
            TextColumn::make('balance')->state(fn (Order $record): int => $record->balanceCents())->formatStateUsing(fn (int $state): string => Lkr::formatWithCurrency($state)),
            TextColumn::make('created_at')->dateTime(),
        ])->filters([
            SelectFilter::make('status')->options(OrderStatus::class),
            SelectFilter::make('source')->options(OrderSource::class),
        ])->recordActions([ViewAction::make()]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Needs attention')->visible(fn (Order $record): bool => $record->needs_attention)
                ->schema([TextEntry::make('attention_note')->hiddenLabel()]),
            Section::make('Order')->schema([
                TextEntry::make('reference'), TextEntry::make('customer_name')->label('Customer'),
                TextEntry::make('active_invoice')->label('Invoice')->state(fn (Order $record): ?string => $record->activeInvoice()?->number)
                    ->url(function (Order $record): ?string {
                        $invoice = $record->activeInvoice();

                        return $invoice === null ? null : InvoiceResource::getUrl('view', ['record' => $invoice]);
                    })
                    ->visible(fn (Order $record): bool => $record->activeInvoice() !== null),
                TextEntry::make('phone_e164')->label('Phone')->visible(fn (TextEntry $component): bool => filled($component->getState())),
                TextEntry::make('email')->visible(fn (TextEntry $component): bool => filled($component->getState())),
                TextEntry::make('source')->badge(), TextEntry::make('status')->badge(),
                TextEntry::make('payment_state')->badge(), TextEntry::make('price_tier')->badge(),
                TextEntry::make('location.name')->label('Location'),
                TextEntry::make('createdBy.name')->label('Created by')->visible(fn (TextEntry $component): bool => filled($component->getState())),
                TextEntry::make('created_at')->dateTime(),
                TextEntry::make('confirmed_at')->dateTime()->visible(fn (TextEntry $component): bool => filled($component->getState())),
                TextEntry::make('fulfilment')->badge()->visible(fn (TextEntry $component): bool => filled($component->getState())),
                TextEntry::make('completed_at')->dateTime()->visible(fn (TextEntry $component): bool => filled($component->getState())),
                TextEntry::make('cancelled_at')->dateTime()->visible(fn (TextEntry $component): bool => filled($component->getState())),
                TextEntry::make('cancel_reason')->formatStateUsing(fn (string $state): string => LateCancelReason::tryFrom($state)?->getLabel() ?? $state)
                    ->visible(fn (TextEntry $component): bool => filled($component->getState())),
            ]),
            Section::make('Totals')->schema([
                TextEntry::make('returned_cents')->label('Returned')->visible(fn (Order $record): bool => $record->returned_cents > 0)->formatStateUsing(fn (int $state): string => Lkr::formatWithCurrency($state)),
                TextEntry::make('subtotal_cents')->label('Subtotal')->formatStateUsing(fn (int $state): string => Lkr::formatWithCurrency($state)),
                TextEntry::make('delivery_cents')->label('Delivery')->formatStateUsing(fn (int $state): string => Lkr::formatWithCurrency($state)),
                TextEntry::make('discount_cents')->label('Discount')->formatStateUsing(fn (int $state): string => Lkr::formatWithCurrency($state)),
                TextEntry::make('total_cents')->label('Total')->formatStateUsing(fn (int $state): string => Lkr::formatWithCurrency($state)),
                TextEntry::make('paid_cents')->label('Paid')->formatStateUsing(fn (int $state): string => Lkr::formatWithCurrency($state)),
                TextEntry::make('balance')->state(fn (Order $record): int => $record->balanceCents())->formatStateUsing(fn (int $state): string => Lkr::formatWithCurrency($state)),
            ]),
            RepeatableEntry::make('lines')->state(fn (Order $record): array => $record->lines->sortBy('sort_order')->all())->schema([
                TextEntry::make('kind')->badge(),
                TextEntry::make('sku')->label('SKU')->visible(fn (TextEntry $component): bool => filled($component->getState())),
                TextEntry::make('description'),
                TextEntry::make('sale_unit')->visible(fn (TextEntry $component): bool => filled($component->getState())), TextEntry::make('qty'),
                TextEntry::make('unit_price_cents')->label('Unit price')->formatStateUsing(fn (int $state): string => Lkr::formatWithCurrency($state)),
                TextEntry::make('line_total_cents')->label('Line total')->formatStateUsing(fn (int $state): string => Lkr::formatWithCurrency($state)),
            ]),
            RepeatableEntry::make('payments')->schema([
                TextEntry::make('print_receipt')->label('Receipt')->state('Print receipt')
                    ->visible(fn (Payment $record): bool => CanPrintReceipt::check($record))
                    ->url(fn (Payment $record): string => route('filament.admin.payments.receipt', ['payment' => $record]))->openUrlInNewTab(),
                TextEntry::make('receipt_number')->visible(fn (TextEntry $component): bool => filled($component->getState())),
                TextEntry::make('kind')->badge(), TextEntry::make('method')->badge(),
                TextEntry::make('amount_cents')->label('Amount')->formatStateUsing(fn (int $state): string => Lkr::formatWithCurrency($state)),
                TextEntry::make('status')->badge(),
                TextEntry::make('reference')->visible(fn (TextEntry $component): bool => filled($component->getState())),
                TextEntry::make('confirmed_at')->dateTime()->visible(fn (TextEntry $component): bool => filled($component->getState())),
            ]),
            Section::make('Returns')->visible(fn (Order $record): bool => $record->salesReturns()->exists())->schema([
                RepeatableEntry::make('salesReturns')->hiddenLabel()
                    ->state(fn (Order $record): array => $record->salesReturns()->with(['lines.orderLine', 'creditNote'])->orderBy('id')->get()->all())->schema([
                        TextEntry::make('created_at')->label('Date')->dateTime(), TextEntry::make('reason'),
                        TextEntry::make('note')->visible(fn (TextEntry $component): bool => filled($component->getState())),
                        TextEntry::make('total_cents')->label('Total')->formatStateUsing(fn (int $state): string => Lkr::formatWithCurrency($state)),
                        TextEntry::make('refund_cents')->label('Refund')->visible(fn (SalesReturn $record): bool => $record->refund_cents > 0)->formatStateUsing(fn (int $state): string => Lkr::formatWithCurrency($state)),
                        RepeatableEntry::make('lines')->schema([
                            TextEntry::make('orderLine.description')->label('Item'), TextEntry::make('qty'),
                            TextEntry::make('disposition')->label('Stock')->state(fn (SalesReturnLine $record): string => $record->restock ? 'Back to stock' : 'Damaged'),
                            TextEntry::make('unit_price_cents')->label('Unit value')->formatStateUsing(fn (int $state): string => Lkr::formatWithCurrency($state)),
                            TextEntry::make('line_total_cents')->label('Value')->formatStateUsing(fn (int $state): string => Lkr::formatWithCurrency($state)),
                        ]),
                        TextEntry::make('creditNote.number')->label('Credit note')->visible(fn (SalesReturn $record): bool => $record->creditNote !== null),
                        TextEntry::make('printCreditNote')->label('Credit note print')->state('Print credit note')
                            ->visible(fn (SalesReturn $record): bool => $record->creditNote !== null)
                            ->url(fn (SalesReturn $record): ?string => $record->creditNote === null ? null : route('filament.admin.credit-notes.print', ['creditNote' => $record->creditNote]))->openUrlInNewTab(),
                    ]),
            ]),
            RepeatableEntry::make('events')->label('Timeline')
                ->state(fn (Order $record): array => $record->events()->with('user')->orderByDesc('created_at')->orderByDesc('id')->get()->all())->schema([
                    TextEntry::make('created_at')->dateTime(),
                    TextEntry::make('type')->badge(),
                    TextEntry::make('from_status')->badge()->visible(fn (TextEntry $component): bool => filled($component->getState())),
                    TextEntry::make('to_status')->badge()->visible(fn (TextEntry $component): bool => filled($component->getState())),
                    TextEntry::make('note')->visible(fn (TextEntry $component): bool => filled($component->getState())),
                    TextEntry::make('user.name')->label('Staff member')->visible(fn (TextEntry $component): bool => filled($component->getState())),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            'new-sale' => NewSale::route('/new-sale'),
            'quote' => EditQuote::route('/{record}/quote'),
            'view' => ViewOrder::route('/{record}'),
        ];
    }

    public static function getRelations(): array
    {
        return [QuoteSnapshotsRelationManager::class];
    }
}
