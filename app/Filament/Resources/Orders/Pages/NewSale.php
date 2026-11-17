<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Actions\Customers\ResolveCustomer;
use App\Actions\Orders\CounterSalePayment;
use App\Actions\Orders\CreateCounterSale;
use App\Domain\Order\AmountTooLarge;
use App\Domain\Order\InvalidOrderTotals;
use App\Enums\PaymentMethod;
use App\Enums\PriceTier;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Support\CustomerPicker;
use App\Filament\Support\InteractsWithOrderLines;
use App\Filament\Support\PurchasingValidation;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/** @property Schema $form */
class NewSale extends Page
{
    use InteractsWithOrderLines;

    protected static string $resource = OrderResource::class;

    protected static ?string $title = 'New sale';

    /** @var array<string, mixed> */
    public array $data = [];

    #[Locked]
    public bool $showStockOverride = false;

    /** @var array<string, Customer|null> */
    private array $cachedCustomers = [];

    /** @param array<string, mixed> $parameters */
    public static function canAccess(array $parameters = []): bool
    {
        return OrderResource::canCreate();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->form->fill();
    }

    public function updated(string $property): void
    {
        $this->resetRequestCaches();
        if ($property === 'data' || $property === 'data.lines' || str_starts_with($property, 'data.lines.')
            || in_array($property, ['data.walk_in', 'data.customer_id'], true)) {
            $this->invalidateStockOverride();
        }
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('completeSale')
                ->footer([
                    Actions::make([
                        Action::make('completeSale')->label('Complete sale')->submit('completeSale'),
                    ]),
                ]),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('Customer')->schema([
                Toggle::make('walk_in')->label('Walk-in customer')->default(true)->live()
                    ->afterStateUpdated(function (mixed $old): void {
                        $this->customerChanged((bool) $old, $this->data['customer_id'] ?? null);
                    }),
                CustomerPicker::make(resolve: fn (mixed $value): ?Customer => $this->resolveCustomer(false, $value))
                    ->visible(fn (Get $get): bool => ! $get('walk_in'))
                    ->required(fn (Get $get): bool => ! $get('walk_in'))
                    ->afterStateUpdated(function (mixed $old): void {
                        $this->customerChanged((bool) ($this->data['walk_in'] ?? true), $old);
                    })
                    ->helperText(fn (): string => 'Price tier: '.$this->currentTier()->getLabel()),
            ]),
            $this->orderLinesRepeater(),
            $this->orderTotalsEntry(),
            Section::make('Payment')->schema([
                Toggle::make('take_payment')->default(true)->live(),
                Select::make('payment.method')->label('Method')->options($this->paymentMethods())->enum(PaymentMethod::class)->default(PaymentMethod::Cash)
                    ->visible(fn (Get $get): bool => (bool) $get('take_payment'))
                    ->required(fn (Get $get): bool => (bool) $get('take_payment'))->live(),
                $this->rupeeInput('payment.amount')->label('Amount')
                    ->visible(fn (Get $get): bool => (bool) $get('take_payment'))
                    ->placeholder(fn (): ?string => ($totals = $this->previewTotals()) === null ? null : $this->rupees($totals->totalCents))
                    ->helperText('Leave blank to pay the full total.'),
                TextInput::make('payment.reference')->label('Reference')->maxLength(255)
                    ->visible(fn (Get $get): bool => (bool) $get('take_payment'))
                    ->required(fn (Get $get): bool => $get('take_payment') && in_array($this->paymentMethod($get('payment.method')), [PaymentMethod::BankTransfer, PaymentMethod::Cheque], true)),
                Textarea::make('payment.note')->label('Note')->maxLength(255)->visible(fn (Get $get): bool => (bool) $get('take_payment')),
            ]),
            Textarea::make('stock_override_reason')->maxLength(255)
                ->visible(fn (): bool => $this->showStockOverride && $this->actor()->can('overrideStock', Order::class))
                ->helperText('Stock is short. Give a reason to sell anyway.'),
        ]);
    }

    public function completeSale(): void
    {
        abort_unless(static::canAccess(), 403);
        $state = $this->form->getState();
        try {
            $customer = ($state['walk_in'] ?? true) ? app(ResolveCustomer::class)->walkIn() : Customer::query()->find($state['customer_id'] ?? null);
            if (! $customer instanceof Customer) {
                throw ValidationException::withMessages(['customer_id' => 'Choose a customer.']);
            }
            $lines = $this->linesFromState($state['lines'] ?? []);
            $payment = null;
            if ($state['take_payment'] ?? false) {
                $amount = $state['payment']['amount'] ?? '';
                $totals = $this->calculateTotals($customer, $lines);
                if ($amount !== '') {
                    $amount = $this->parseMoney((string) $amount, 'payment.amount');
                } else {
                    $amount = $totals->totalCents;
                }
                if ($amount !== 0 || $totals->totalCents !== 0) {
                    $method = $this->paymentMethod($state['payment']['method'] ?? null);
                    if ($method === null) {
                        throw ValidationException::withMessages(['payment.method' => 'Choose a payment method.']);
                    }
                    $payment = new CounterSalePayment($method, $amount, $state['payment']['reference'] ?? null, $state['payment']['note'] ?? null);
                }
            }
            $order = app(CreateCounterSale::class)->handle($customer, $lines, $this->actor(), $payment, $state['stock_override_reason'] ?? null);
        } catch (ValidationException $exception) {
            foreach ($exception->errors()['lines'] ?? [] as $message) {
                if (str_starts_with($message, 'Stock is short.')) {
                    $this->showStockOverride = true;
                }
            }
            throw PurchasingValidation::forForm($exception);
        } catch (InvalidOrderTotals|AmountTooLarge $exception) {
            throw PurchasingValidation::forForm(ValidationException::withMessages(['lines' => $exception->getMessage()]));
        }
        $receipt = $order->payments->first()?->receipt_number;
        Notification::make()->success()->title('Sale '.$order->reference.' recorded'.($receipt === null ? '' : ' — receipt '.$receipt))->send();
        $this->redirect(OrderResource::getUrl('view', ['record' => $order]));
    }

    private function paymentMethod(mixed $state): ?PaymentMethod
    {
        if ($state instanceof PaymentMethod) {
            return $state;
        }

        return is_string($state) ? PaymentMethod::tryFrom($state) : null;
    }

    protected function selectedCustomer(): ?Customer
    {
        return $this->resolveCustomer((bool) ($this->data['walk_in'] ?? true), $this->data['customer_id'] ?? null);
    }

    private function resolveCustomer(bool $walkIn, mixed $customerId): ?Customer
    {
        $key = $walkIn ? 'walk_in' : 'customer:'.(is_int($customerId) || is_string($customerId) ? $customerId : '');
        if (! array_key_exists($key, $this->cachedCustomers)) {
            $this->cachedCustomers[$key] = $walkIn
                ? Customer::query()->walkIn()->first()
                : CustomerPicker::query()->find(is_int($customerId) || is_string($customerId) ? $customerId : null);
        }

        return $this->cachedCustomers[$key];
    }

    protected function currentTier(): PriceTier
    {
        $customer = $this->selectedCustomer();

        return $customer === null ? PriceTier::Retail : $customer->price_tier;
    }

    private function customerChanged(bool $oldWalkIn, mixed $oldCustomerId): void
    {
        $oldCustomer = $this->resolveCustomer($oldWalkIn, $oldCustomerId);
        $oldTier = $oldCustomer === null ? PriceTier::Retail : $oldCustomer->price_tier;
        $newTier = $this->currentTier();
        $this->invalidateStockOverride();
        $this->resetPreview();
        if ($oldTier === $newTier) {
            return;
        }
        $this->repriceOrderLines($oldTier, $newTier);
    }

    protected function orderLinesChanged(): void
    {
        $this->invalidateStockOverride();
        $this->resetPreview();
    }

    private function invalidateStockOverride(): void
    {
        $this->showStockOverride = false;
        $this->data['stock_override_reason'] = null;
    }

    private function resetRequestCaches(): void
    {
        $this->cachedCustomers = [];
        $this->resetOrderLineCaches();
    }

    /** @return array<string, string> */
    private function paymentMethods(): array
    {
        $methods = [];
        foreach (PaymentMethod::cases() as $method) {
            if ($method !== PaymentMethod::Payhere) {
                $methods[$method->value] = $method->getLabel();
            }
        }

        return $methods;
    }

    private function actor(): User
    {
        /** @var User $actor */
        $actor = Filament::auth()->user();

        return $actor;
    }
}
