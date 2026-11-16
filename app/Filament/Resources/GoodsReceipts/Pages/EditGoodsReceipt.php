<?php

namespace App\Filament\Resources\GoodsReceipts\Pages;

use App\Actions\Purchasing\DeleteGoodsReceipt;
use App\Actions\Purchasing\PostGoodsReceipt;
use App\Actions\Purchasing\UpdateGoodsReceiptDetails;
use App\Domain\Money\Lkr;
use App\Enums\GoodsReceiptStatus;
use App\Filament\Resources\GoodsReceipts\GoodsReceiptResource;
use App\Filament\Support\PurchasingValidation;
use App\Models\GoodsReceipt;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Components\Attributes\Exposed;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** @extends EditRecord<GoodsReceipt> */
class EditGoodsReceipt extends EditRecord
{
    protected static string $resource = GoodsReceiptResource::class;

    #[Unexposed]
    public function hydrate(): void
    {
        Gate::forUser($this->actor())->authorize('create', GoodsReceipt::class);
        Gate::forUser($this->actor())->authorize('view', $this->getRecord());
    }

    #[Exposed]
    public function save(bool $shouldRedirect = true, bool $shouldSendSavedNotification = true): void
    {
        $record = $this->getRecord()->fresh();
        abort_if($record === null, 404);
        Gate::forUser($this->actor())->authorize('create', GoodsReceipt::class);
        Gate::forUser($this->actor())->authorize('view', $record);
        if ($record->status !== GoodsReceiptStatus::Draft) {
            Notification::make()->danger()->title('This goods received note has been posted or voided and can no longer be edited.')->send();

            return;
        }

        parent::save($shouldRedirect, $shouldSendSavedNotification);
    }

    /** @param array<string, mixed> $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return app(UpdateGoodsReceiptDetails::class)->handle($this->getRecord(), $this->actor(), (int) $data['supplier_id'], (string) $data['received_on'], $data['supplier_reference'] ?? null, $data['note'] ?? null);
        } catch (ValidationException $exception) {
            throw PurchasingValidation::forForm($exception);
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('post')->label('Post')->authorize(fn (): bool => $this->canWrite())->requiresConfirmation()
                ->modalDescription(function (): string {
                    $receipt = $this->getRecord()->fresh() ?? $this->getRecord();

                    $count = $receipt->lines()->count();

                    return $count.($count === 1 ? ' line, ' : ' lines, ').$receipt->lines()->sum('qty').' units, '.Lkr::formatWithCurrency($receipt->total_cost_cents).'. Post this goods received note?';
                })->action(function (): void {
                    try {
                        $receipt = app(PostGoodsReceipt::class)->handle($this->getRecord(), $this->actor());
                    } catch (ValidationException $exception) {
                        PurchasingValidation::notify($exception);

                        return;
                    }
                    Notification::make()->success()->title('Goods received posted')->body($receipt->number)->send();
                    $this->redirect(GoodsReceiptResource::getUrl('view', ['record' => $receipt]));
                }),
            Action::make('delete')->label('Delete draft')->color('danger')->authorize(fn (): bool => $this->canWrite())->requiresConfirmation()
                ->action(function (): void {
                    try {
                        app(DeleteGoodsReceipt::class)->handle($this->getRecord(), $this->actor());
                        $this->getRecord()->exists = false;
                    } catch (ValidationException $exception) {
                        PurchasingValidation::notify($exception);

                        return;
                    }
                    Notification::make()->success()->title('Draft deleted')->send();
                    $this->redirect(GoodsReceiptResource::getUrl());
                }),
        ];
    }

    private function canWrite(): bool
    {
        return Filament::auth()->user()?->can('create', GoodsReceipt::class) ?? false;
    }

    private function actor(): User
    {
        /** @var User $actor */
        $actor = Filament::auth()->user();

        return $actor;
    }
}
