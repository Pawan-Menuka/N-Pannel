<?php

use App\Enums\AcceptedVia;
use App\Enums\CustomerType;
use App\Enums\DocumentKind;
use App\Enums\DocumentStatus;
use App\Enums\FulfilmentMethod;
use App\Enums\GoodsReceiptStatus;
use App\Enums\OrderEventType;
use App\Enums\OrderLineKind;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Enums\PaymentState;
use App\Enums\PaymentStatus;
use App\Enums\PriceTier;
use App\Enums\SaleUnit;
use App\Enums\StockReason;
use App\Enums\StockStatus;
use App\Enums\StocktakeStatus;
use App\Enums\UserRole;
use Filament\Support\Contracts\HasLabel;

it('gives every enum case a human label', function (string $enumClass) {
    foreach ($enumClass::cases() as $case) {
        expect($case)->toBeInstanceOf(HasLabel::class);
        expect(trim($case->getLabel()))->not->toBe('');
    }
})->with([
    'UserRole' => [UserRole::class],
    'SaleUnit' => [SaleUnit::class],
    'StockStatus' => [StockStatus::class],
    'StockReason' => [StockReason::class],
    'OrderStatus' => [OrderStatus::class],
    'OrderSource' => [OrderSource::class],
    'AcceptedVia' => [AcceptedVia::class],
    'PaymentState' => [PaymentState::class],
    'PaymentKind' => [PaymentKind::class],
    'PaymentMethod' => [PaymentMethod::class],
    'PaymentStatus' => [PaymentStatus::class],
    'FulfilmentMethod' => [FulfilmentMethod::class],
    'OrderLineKind' => [OrderLineKind::class],
    'OrderEventType' => [OrderEventType::class],
    'CustomerType' => [CustomerType::class],
    'PriceTier' => [PriceTier::class],
    'DocumentKind' => [DocumentKind::class],
    'DocumentStatus' => [DocumentStatus::class],
    'GoodsReceiptStatus' => [GoodsReceiptStatus::class],
    'StocktakeStatus' => [StocktakeStatus::class],
]);

it('maps document kinds to their number prefixes', function () {
    expect(DocumentKind::Invoice->prefix())->toBe('INV');
    expect(DocumentKind::Receipt->prefix())->toBe('RCP');
    expect(DocumentKind::CreditNote->prefix())->toBe('CRN');
    expect(DocumentKind::GoodsReceipt->prefix())->toBe('GRN');
});
