<?php

namespace App\Filament\Resources\GoodsReceipts\Pages;

use App\Actions\Purchasing\VoidGoodsReceipt;
use App\Filament\Resources\GoodsReceipts\GoodsReceiptResource;
use App\Filament\Support\PurchasingValidation;
use App\Models\GoodsReceipt;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Validation\ValidationException;

/** @extends ViewRecord<GoodsReceipt> */
class ViewGoodsReceipt extends ViewRecord
{
    protected static string $resource = GoodsReceiptResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            Action::make('void')->label('Void')->color('danger')->requiresConfirmation()
                ->authorize(fn (): bool => $this->actor()->can('void', $this->getRecord()))
                ->schema([
                    Textarea::make('reason')->required()->maxLength(255),
                    Checkbox::make('override')->label('Override: allow stock to go negative')->default(false)
                        ->visible(fn (): bool => $this->actor()->can('voidOverride', $this->getRecord())),
                ])->action(function (array $data): void {
                    try {
                        app(VoidGoodsReceipt::class)->handle($this->getRecord(), $this->actor(), (string) $data['reason'], (bool) ($data['override'] ?? false));
                    } catch (ValidationException $exception) {
                        PurchasingValidation::notify($exception);

                        return;
                    }
                    Notification::make()->success()->title('Goods received voided')->send();
                    $this->redirect(GoodsReceiptResource::getUrl('view', ['record' => $this->getRecord()]));
                }),
        ];
    }

    private function actor(): User
    {
        /** @var User $actor */
        $actor = Filament::auth()->user();

        return $actor;
    }
}
