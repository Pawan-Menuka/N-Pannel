<?php

namespace App\Filament\Support;

use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Js;

trait HasInvoiceLinkAction
{
    abstract protected function invoiceLinkAuthorized(): bool;

    /** The bearer URL lives only in the mounted modal, never in a model or notification. */
    public function invoiceLinkAction(): Action
    {
        return Action::make('invoiceLink')->authorize(fn (): bool => $this->invoiceLinkAuthorized())
            ->modalHeading('Invoice link')->modalSubmitAction(false)->modalCancelActionLabel('Close')
            ->schema([
                TextInput::make('url')->label('Link')->readOnly()->copyable()->dehydrated(false),
                Textarea::make('whatsAppText')->label('WhatsApp text')->readOnly()->rows(5)->dehydrated(false)
                    ->hintAction(Action::make('copyWhatsAppText')->label('Copy')->alpineClickHandler(fn (Get $get): string => 'window.navigator.clipboard.writeText('.Js::from($get('whatsAppText')).')')),
                TextInput::make('whatsAppUrl')->hidden()->dehydrated(false),
                TextEntry::make('linkNotice')->hiddenLabel()->state('This link replaces any earlier link for this invoice.'),
            ])->extraModalFooterActions(fn (array $arguments): array => [
                Action::make('openWhatsApp')->label('Open WhatsApp')->url($arguments['whatsAppUrl'] ?? null)->openUrlInNewTab()->visible(filled($arguments['whatsAppUrl'] ?? null)),
                Action::make('openInvoice')->label('Open invoice')->url($arguments['url'] ?? null)->openUrlInNewTab(),
            ])->fillForm(fn (array $arguments): array => [
                'url' => $arguments['url'] ?? null, 'whatsAppText' => $arguments['whatsAppText'] ?? null, 'whatsAppUrl' => $arguments['whatsAppUrl'] ?? null,
            ]);
    }
}
