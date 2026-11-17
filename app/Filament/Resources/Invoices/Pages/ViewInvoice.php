<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Actions\Invoices\BuildInvoiceDocument;
use App\Actions\Invoices\GetInvoiceLink;
use App\Actions\Invoices\InvoiceDocument;
use App\Actions\Invoices\VoidInvoice;
use App\Enums\DocumentStatus;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Support\HasInvoiceLinkAction;
use App\Filament\Support\PurchasingValidation;
use App\Models\Invoice;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Panel;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Routing\Route;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;

/**
 * @extends ViewRecord<Invoice>
 *
 * @property-read InvoiceDocument $document
 */
class ViewInvoice extends ViewRecord
{
    use HasInvoiceLinkAction;

    protected static string $resource = InvoiceResource::class;

    public static function route(string $path): PageRegistration
    {
        $registration = parent::route($path);

        return new PageRegistration(static::class, fn (Panel $panel): ?Route => $registration->registerRoute($panel)?->whereNumber('record'));
    }

    #[Computed]
    public function document(): InvoiceDocument
    {
        return app(BuildInvoiceDocument::class)->handle($this->getRecord());
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('getInvoiceLink')->label('Get new invoice link')
                ->visible(fn (): bool => $this->getRecord()->status === DocumentStatus::Issued && $this->invoiceLinkAuthorized())
                ->action(function (): void {
                    try {
                        $link = app(GetInvoiceLink::class)->handle($this->getRecord(), $this->invoiceActor());
                    } catch (ValidationException $exception) {
                        PurchasingValidation::notify($exception);
                        throw new Halt;
                    }
                    $this->getRecord()->refresh();
                    $this->replaceMountedAction('invoiceLink', ['url' => $link->url, 'whatsAppText' => $link->whatsAppText, 'whatsAppUrl' => $link->whatsAppUrl]);
                }),
            Action::make('printInvoice')->label('Print')->url(fn (): string => route('filament.admin.invoices.print', ['invoice' => $this->getRecord()]))->openUrlInNewTab(),
            Action::make('voidInvoice')->label('Void')->color('danger')
                ->visible(fn (): bool => ($this->getRecord()->status === DocumentStatus::Issued || in_array('voidInvoice', array_column($this->mountedActions, 'name'), true))
                    && $this->invoiceActor()->can('void', $this->getRecord()))
                ->schema([Textarea::make('reason')->required()->maxLength(255)])
                ->action(function (array $data): void {
                    try {
                        $invoice = app(VoidInvoice::class)->handle($this->getRecord(), $data['reason'], $this->invoiceActor());
                    } catch (ValidationException $exception) {
                        PurchasingValidation::notify($exception);
                        throw new Halt;
                    }
                    $this->getRecord()->refresh();
                    unset($this->document);
                    Notification::make()->success()->title('Invoice '.$invoice->number.' voided')->send();
                }),
        ];
    }

    protected function invoiceLinkAuthorized(): bool
    {
        return $this->invoiceActor()->can('view', $this->getRecord());
    }

    private function invoiceActor(): User
    {
        /** @var User $by */
        $by = Filament::auth()->user();

        return $by;
    }
}
