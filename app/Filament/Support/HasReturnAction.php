<?php

namespace App\Filament\Support;

use App\Actions\Returns\CreateReturn;
use App\Actions\Returns\ReturnLineInput;
use App\Actions\Returns\ReturnRefundInput;
use App\Domain\Money\Lkr;
use App\Enums\PaymentMethod;
use App\Models\Order;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Exceptions\Halt;
use Illuminate\Validation\ValidationException;

trait HasReturnAction
{
    abstract protected function fulfilmentRecord(): Order;

    protected function recordReturnAction(): Action
    {
        return Action::make('recordReturn')->label('Record return')
            ->visible(fn (): bool => app(CreateReturn::class)->canRecord($this->fulfilmentRecord(), $this->orderActor()))
            ->fillForm(fn (): array => ['lines' => $this->returnRows(), 'refund' => false, 'method' => PaymentMethod::Cash->value])
            ->schema([
                Repeater::make('lines')->label('Products')->addable(false)->deletable(false)->reorderable(false)
                    ->schema([
                        Hidden::make('order_line_id')->required(), Hidden::make('returnable_qty'),
                        TextInput::make('item')->label('Item')->disabled()->dehydrated(false),
                        TextInput::make('qty')->label('Return quantity')->integer()->minValue(0)
                            ->maxValue(fn (Get $get): int => (int) $get('returnable_qty'))->default(0)->required()->live(),
                        Toggle::make('restock')->label('Back to stock')->default(true),
                        OrderLineForm::rupeeInput('unit_value')->label('Unit value')->required()->live(onBlur: true),
                    ])->live(),
                Textarea::make('reason')->required()->maxLength(255),
                Textarea::make('note')->maxLength(2000),
                Section::make('Refund')->schema([
                    Toggle::make('refund')->label('Record refund')->live(),
                    Select::make('method')->options($this->staffPaymentMethods())
                        ->visible(fn (Get $get): bool => (bool) $get('refund'))->required(),
                    OrderLineForm::rupeeInput('refund_amount')->label('Refund amount')
                        ->visible(fn (Get $get): bool => (bool) $get('refund'))->required()
                        ->helperText(fn (Get $get): string => $this->returnRefundHelper($get('lines') ?? [])),
                ]),
            ])->action(function (array $data): void {
                try {
                    $return = app(CreateReturn::class)->handle(
                        $this->fulfilmentRecord(), $this->returnInputs($data['lines'] ?? []),
                        $data['reason'], $data['note'] ?? null,
                        ($data['refund'] ?? false) ? new ReturnRefundInput(
                            $this->staffPaymentMethod($data['method'] ?? null),
                            OrderLineForm::parseMoney((string) ($data['refund_amount'] ?? ''), 'refund_amount'),
                        ) : null,
                        $this->orderActor(),
                    );
                } catch (ValidationException $exception) {
                    PurchasingValidation::notify($exception);
                    throw new Halt;
                }
                $this->fulfilmentRecord()->refresh();
                Notification::make()->success()->title('Return recorded'.($return->creditNote === null ? '' : ' — credit note '.$return->creditNote->number))->send();
            });
    }

    /** @return list<array{order_line_id: int, returnable_qty: int, item: string, qty: int, restock: bool, unit_value: string}> */
    private function returnRows(): array
    {
        $rows = [];
        foreach (app(CreateReturn::class)->productLines($this->fulfilmentRecord()) as $line) {
            $remaining = $line->qty - (int) $line->getAttribute('already_returned_qty');
            if ($remaining > 0) {
                $rows[] = [
                    'order_line_id' => $line->id, 'returnable_qty' => $remaining,
                    'item' => $line->sku.' — '.$line->description.' (sold '.$line->qty.', returnable '.$remaining.')',
                    'qty' => 0, 'restock' => true, 'unit_value' => OrderLineForm::rupees(app(CreateReturn::class)->defaultUnitValue($this->fulfilmentRecord(), $line)),
                ];
            }
        }

        return $rows;
    }

    /**
     * @param  array<array{order_line_id?: int|string, qty?: int|string|null, unit_value?: string, restock?: bool}>  $rows
     * @return list<ReturnLineInput>
     */
    private function returnInputs(array $rows): array
    {
        $inputs = [];
        foreach ($rows as $row) {
            if ((int) ($row['qty'] ?? 0) === 0) {
                continue;
            }
            $inputs[] = new ReturnLineInput(
                (int) ($row['order_line_id'] ?? 0), (int) ($row['qty'] ?? 0),
                OrderLineForm::parseMoney((string) ($row['unit_value'] ?? ''), 'unit_value'), (bool) ($row['restock'] ?? false),
            );
        }

        return $inputs;
    }

    /** @param array<array{order_line_id?: int|string, qty?: int|string|null, unit_value?: string, restock?: bool}> $rows */
    private function returnRefundHelper(array $rows): string
    {
        try {
            $result = app(CreateReturn::class)->preview($this->fulfilmentRecord(), $this->returnInputs($rows));

            return 'Up to '.Lkr::formatWithCurrency($result['max_refundable_cents']).' can be refunded';
        } catch (ValidationException) {
            return 'Up to Rs 0.00 can be refunded';
        }
    }
}
