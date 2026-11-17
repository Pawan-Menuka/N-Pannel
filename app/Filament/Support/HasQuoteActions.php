<?php

namespace App\Filament\Support;

use App\Actions\Orders\AcceptQuoteByStaff;
use App\Actions\Orders\SendQuote;
use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Js;
use Illuminate\Validation\ValidationException;

trait HasQuoteActions
{
    abstract protected function quoteRecord(): Order;

    protected function saveBeforeSending(): void {}

    /** @return list<Action> */
    protected function quoteActions(): array
    {
        return [
            Action::make('getQuoteLink')->label('Get quote link')
                ->visible(fn (): bool => $this->quoteRecord()->status === OrderStatus::Quoted && $this->quoteActor()->can('send', $this->quoteRecord()))
                ->action(function (): void {
                    $this->saveBeforeSending();
                    try {
                        $link = app(SendQuote::class)->handle($this->quoteRecord(), $this->quoteActor());
                    } catch (ValidationException $exception) {
                        PurchasingValidation::notify($exception);
                        throw new Halt;
                    }
                    $this->quoteRecord()->refresh();
                    $this->replaceMountedAction('quoteLink', ['url' => $link->url, 'whatsAppText' => $link->whatsAppText, 'whatsAppUrl' => $link->whatsAppUrl]);
                }),
            Action::make('markAccepted')->label('Mark accepted')
                ->visible(fn (): bool => $this->quoteRecord()->status === OrderStatus::Quoted && $this->quoteActor()->can('accept', $this->quoteRecord()))
                ->schema([
                    Textarea::make('note')->label('Note')->maxLength(255),
                    Textarea::make('stock_override_reason')->label('Stock override reason')->maxLength(255)
                        ->helperText('Only needed if stock is short.')
                        ->visible(fn (): bool => $this->quoteActor()->can('overrideStock', Order::class)),
                ])->action(function (array $data): void {
                    try {
                        $order = app(AcceptQuoteByStaff::class)->handle($this->quoteRecord(), $this->quoteActor(), $data['note'] ?? null, $data['stock_override_reason'] ?? null);
                    } catch (ValidationException $exception) {
                        PurchasingValidation::notify($exception);
                        throw new Halt;
                    }
                    Notification::make()->success()->title('Quote '.$order->reference.' accepted')->send();
                    $this->redirect(OrderResource::getUrl('view', ['record' => $order]));
                }),
            Action::make('printQuote')->label('Print')
                ->visible(fn (): bool => $this->quoteRecord()->quote_revision >= 1)
                ->url(fn (): string => route('filament.admin.quotes.print', ['order' => $this->quoteRecord(), 'revision' => $this->quoteRecord()->quote_revision]))->openUrlInNewTab(),
        ];
    }

    /** The bearer URL lives only in this transient modal, never in a model or notification. */
    public function quoteLinkAction(): Action
    {
        return Action::make('quoteLink')->authorize(fn (): bool => $this->quoteActor()->can('view', $this->quoteRecord()))
            ->modalHeading('Quote link')->modalSubmitAction(false)->modalCancelActionLabel('Close')
            ->schema([
                TextInput::make('url')->label('Link')->readOnly()->copyable()->dehydrated(false),
                Textarea::make('whatsAppText')->label('WhatsApp text')->readOnly()->rows(5)->dehydrated(false)
                    ->hintAction(Action::make('copyWhatsAppText')->label('Copy')->alpineClickHandler(fn (Get $get): string => 'window.navigator.clipboard.writeText('.Js::from($get('whatsAppText')).')')),
                TextInput::make('whatsAppUrl')->hidden()->dehydrated(false),
                TextEntry::make('linkNotice')->hiddenLabel()->state('This link replaces any earlier link for this quote.'),
            ])
            ->extraModalFooterActions(fn (array $arguments): array => [
                Action::make('openWhatsApp')->label('Open WhatsApp')->url($arguments['whatsAppUrl'] ?? null)->openUrlInNewTab()
                    ->visible(filled($arguments['whatsAppUrl'] ?? null)),
                Action::make('openQuote')->label('Open quote')->url($arguments['url'] ?? null)->openUrlInNewTab(),
            ])
            ->fillForm(fn (array $arguments): array => [
                'url' => $arguments['url'] ?? null, 'whatsAppText' => $arguments['whatsAppText'] ?? null, 'whatsAppUrl' => $arguments['whatsAppUrl'] ?? null,
            ]);
    }

    private function quoteActor(): User
    {
        /** @var User $by */
        $by = Filament::auth()->user();

        return $by;
    }
}
