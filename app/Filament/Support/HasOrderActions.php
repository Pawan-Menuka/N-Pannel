<?php

namespace App\Filament\Support;

use App\Actions\Orders\ClearAttention;
use App\Actions\Orders\TransitionOrder;
use App\Actions\Payments\RecordPayment;
use App\Actions\Payments\RecordRefund;
use App\Domain\Money\Lkr;
use App\Enums\LateCancelReason;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Exceptions\Halt;
use Illuminate\Validation\ValidationException;

trait HasOrderActions
{
    abstract protected function fulfilmentRecord(): Order;

    /** @return list<Action> */
    protected function orderActions(): array
    {
        return [
            Action::make('moveTo')->label('Move to…')
                ->visible(fn (): bool => $this->orderActor()->can('transition', $this->fulfilmentRecord()) && TransitionOrder::targetsFor($this->fulfilmentRecord()) !== [])
                ->schema([
                    Select::make('to')->label('Move to')->options(fn (): array => $this->transitionOptions())->required()->live(),
                    Textarea::make('cancel_reason')->label('Cancellation reason')->maxLength(255)
                        ->visible(fn (Get $get): bool => $get('to') === OrderStatus::Cancelled->value && ! $this->isLateCancellation())
                        ->required(),
                    Select::make('late_cancel_reason')->label('Cancellation reason')->options(LateCancelReason::class)
                        ->visible(fn (Get $get): bool => $get('to') === OrderStatus::Cancelled->value && $this->isLateCancellation())
                        ->required(),
                    Textarea::make('complete_override_reason')->label('Completion override reason')->maxLength(255)
                        ->visible(fn (Get $get): bool => $get('to') === OrderStatus::Completed->value && $this->fulfilmentRecord()->balanceCents() > 0 && $this->orderActor()->can('completeOverride', $this->fulfilmentRecord()))
                        ->helperText(fn (): string => Lkr::formatWithCurrency($this->fulfilmentRecord()->balanceCents()).' is still owed.')
                        ->required(),
                    Textarea::make('note')->label('Note')->maxLength(255),
                ])->action(function (array $data): void {
                    try {
                        $to = OrderStatus::tryFrom($data['to'] ?? '');
                        if ($to === null) {
                            throw ValidationException::withMessages(['to' => 'Choose a status.']);
                        }
                        $order = app(TransitionOrder::class)->handle(
                            $this->fulfilmentRecord(), $to, $this->orderActor(), $data['note'] ?? null,
                            $this->isLateCancellation() ? $this->lateCancelReasonValue($data['late_cancel_reason'] ?? null) : ($data['cancel_reason'] ?? null),
                            $data['complete_override_reason'] ?? null,
                        );
                    } catch (ValidationException $exception) {
                        PurchasingValidation::notify($exception);
                        throw new Halt;
                    }
                    $this->fulfilmentRecord()->refresh();
                    Notification::make()->success()->title('Moved to '.$order->status->getLabel())->send();
                }),
            Action::make('recordPayment')->label('Record payment')
                ->visible(fn (): bool => $this->orderActor()->can('create', Payment::class)
                    && in_array($this->fulfilmentRecord()->status, [OrderStatus::Quoted, OrderStatus::Confirmed, OrderStatus::Processing, OrderStatus::ReadyForCollection, OrderStatus::Dispatched, OrderStatus::Cancelled], true)
                    && ($this->fulfilmentRecord()->status === OrderStatus::Cancelled || $this->fulfilmentRecord()->balanceCents() > 0))
                ->schema([
                    Select::make('method')->options($this->staffPaymentMethods())->default(PaymentMethod::Cash->value)->required()->live(),
                    OrderLineForm::rupeeInput('amount')->required()->default(fn (): ?string => in_array($this->fulfilmentRecord()->status, [OrderStatus::Cancelled, OrderStatus::Expired], true) ? null : OrderLineForm::rupees($this->fulfilmentRecord()->balanceCents())),
                    TextInput::make('reference')->maxLength(255)
                        ->required(fn (Get $get): bool => in_array($get('method'), [PaymentMethod::BankTransfer->value, PaymentMethod::Cheque->value], true)),
                    Textarea::make('note')->maxLength(255),
                ])->action(function (array $data): void {
                    try {
                        $payment = app(RecordPayment::class)->handle(
                            $this->fulfilmentRecord(), $this->staffPaymentMethod($data['method'] ?? null),
                            OrderLineForm::parseMoney((string) $data['amount'], 'amount'), $data['reference'] ?? null, $data['note'] ?? null, $this->orderActor(),
                        );
                    } catch (ValidationException $exception) {
                        PurchasingValidation::notify($exception);
                        throw new Halt;
                    }
                    $this->fulfilmentRecord()->refresh();
                    Notification::make()->success()->title('Payment recorded — receipt '.$payment->receipt_number)->send();
                }),
            Action::make('recordRefund')->label('Record refund')
                ->visible(fn (): bool => $this->orderActor()->can('refund', Payment::class) && $this->fulfilmentRecord()->paid_cents > 0)
                ->schema([
                    Select::make('method')->options($this->staffPaymentMethods())->default(PaymentMethod::Cash->value)->required(),
                    OrderLineForm::rupeeInput('amount')->required(),
                    Textarea::make('note')->required()->maxLength(255),
                ])->action(function (array $data): void {
                    try {
                        app(RecordRefund::class)->handle($this->fulfilmentRecord(), $this->staffPaymentMethod($data['method'] ?? null), OrderLineForm::parseMoney((string) $data['amount'], 'amount'), $data['note'], $this->orderActor());
                    } catch (ValidationException $exception) {
                        PurchasingValidation::notify($exception);
                        throw new Halt;
                    }
                    $this->fulfilmentRecord()->refresh();
                    Notification::make()->success()->title('Refund recorded')->send();
                }),
            Action::make('clearAttention')->label('Clear attention')
                ->visible(fn (): bool => $this->fulfilmentRecord()->needs_attention && $this->orderActor()->can('clearAttention', $this->fulfilmentRecord()))
                ->schema([Textarea::make('note')->required()->maxLength(255)])
                ->action(function (array $data): void {
                    try {
                        app(ClearAttention::class)->handle($this->fulfilmentRecord(), $data['note'], $this->orderActor());
                    } catch (ValidationException $exception) {
                        PurchasingValidation::notify($exception);
                        throw new Halt;
                    }
                    $this->fulfilmentRecord()->refresh();
                    Notification::make()->success()->title('Attention cleared')->send();
                }),
        ];
    }

    /** @return array<string, string> */
    private function transitionOptions(): array
    {
        $options = [];
        foreach (TransitionOrder::targetsFor($this->fulfilmentRecord()) as $status) {
            $options[$status->value] = $status->getLabel();
        }

        return $options;
    }

    private function isLateCancellation(): bool
    {
        return in_array($this->fulfilmentRecord()->status, [OrderStatus::ReadyForCollection, OrderStatus::Dispatched], true);
    }

    private function lateCancelReasonValue(mixed $state): ?string
    {
        if ($state instanceof LateCancelReason) {
            return $state->value;
        }

        return is_string($state) ? $state : null;
    }

    /** @return array<string, string> */
    private function staffPaymentMethods(): array
    {
        $options = [];
        foreach (PaymentMethod::cases() as $method) {
            if ($method !== PaymentMethod::Payhere) {
                $options[$method->value] = $method->getLabel();
            }
        }

        return $options;
    }

    private function staffPaymentMethod(mixed $state): PaymentMethod
    {
        $method = $state instanceof PaymentMethod ? $state : (is_string($state) ? PaymentMethod::tryFrom($state) : null);
        if ($method === null || $method === PaymentMethod::Payhere) {
            throw ValidationException::withMessages(['method' => 'Choose a staff payment method.']);
        }

        return $method;
    }

    private function orderActor(): User
    {
        /** @var User $by */
        $by = Filament::auth()->user();

        return $by;
    }
}
