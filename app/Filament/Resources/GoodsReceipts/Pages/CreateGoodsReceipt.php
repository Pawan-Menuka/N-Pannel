<?php

namespace App\Filament\Resources\GoodsReceipts\Pages;

use App\Actions\Purchasing\CreateGoodsReceipt as CreateGoodsReceiptAction;
use App\Filament\Resources\GoodsReceipts\GoodsReceiptResource;
use App\Filament\Support\PurchasingValidation;
use App\Models\GoodsReceipt;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/** @extends CreateRecord<GoodsReceipt> */
class CreateGoodsReceipt extends CreateRecord
{
    protected static string $resource = GoodsReceiptResource::class;

    /** @param array<string, mixed> $data */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $actor */
        $actor = Filament::auth()->user();
        try {
            return app(CreateGoodsReceiptAction::class)->handle($actor, (int) $data['supplier_id'], (string) $data['received_on'], supplierReference: $data['supplier_reference'] ?? null, note: $data['note'] ?? null);
        } catch (ValidationException $exception) {
            throw PurchasingValidation::forForm($exception);
        }
    }

    protected function getRedirectUrl(): string
    {
        return GoodsReceiptResource::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
