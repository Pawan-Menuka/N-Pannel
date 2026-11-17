<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Actions\Customers\ResolveCustomer;
use App\Actions\Customers\SearchCustomers;
use App\Actions\Orders\CounterSaleLine;
use App\Actions\Orders\CounterSalePayment;
use App\Actions\Orders\CounterSalePricing;
use App\Actions\Orders\CreateCounterSale;
use App\Actions\Orders\PrepareCounterSaleLines;
use App\Domain\Money\InvalidAmount;
use App\Domain\Money\Lkr;
use App\Domain\Order\AmountTooLarge;
use App\Domain\Order\InvalidOrderTotals;
use App\Domain\Order\OrderTotals;
use App\Domain\Order\Totals;
use App\Enums\OrderLineKind;
use App\Enums\PaymentMethod;
use App\Enums\PriceTier;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Support\PurchasingValidation;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Order;
use App\Models\StockLevel;
use App\Models\User;
use App\Models\Variant;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/** @property Schema $form */
class NewSale extends Page
{
    protected static string $resource = OrderResource::class;

    protected static ?string $title = 'New sale';

    /** @var array<string, mixed> */
    public array $data = [];

    #[Locked]
    public bool $showStockOverride = false;

    /** @var array<string, Customer|null> */
    private array $cachedCustomers = [];

    private ?Location $cachedLocation = null;

    /** @var Collection<int, Variant>|null */
    private ?Collection $cachedVariants = null;

    /** @var array<int, true> */
    private array $loadedVariantIds = [];

    private bool $previewLoaded = false;

    private ?OrderTotals $cachedPreview = null;

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
                Select::make('customer_id')->label('Customer')->searchable()->live()
                    ->visible(fn (Get $get): bool => ! $get('walk_in'))
                    ->required(fn (Get $get): bool => ! $get('walk_in'))
                    ->afterStateUpdated(function (mixed $old): void {
                        $this->customerChanged((bool) ($this->data['walk_in'] ?? true), $old);
                    })
                    ->getSearchResultsUsing($this->searchCustomers(...))
                    ->getOptionLabelUsing(function (mixed $value): ?string {
                        $customer = $this->resolveCustomer(false, $value);

                        return $customer instanceof Customer ? $this->customerLabel($customer) : null;
                    })
                    ->helperText(fn (): string => 'Price tier: '.$this->currentTier()->getLabel())
                    ->createOptionForm([
                        TextInput::make('phone_e164')->label('Phone')->tel()->required(),
                        TextInput::make('name')->required()->maxLength(255),
                    ])->createOptionUsing(function (array $data, Schema $schema): int {
                        try {
                            return app(ResolveCustomer::class)->handle($data['phone_e164'], $data['name'], $this->actor())->customer->id;
                        } catch (ValidationException $exception) {
                            throw PurchasingValidation::forForm($exception, $schema->getStatePath() ?? 'mountedActions.0.data');
                        }
                    }),
            ]),
            Repeater::make('lines')->live()->minItems(1)->maxItems(200)->defaultItems(1)
                ->afterStateUpdated(function (): void {
                    $this->invalidateStockOverride();
                    $this->resetPreview();
                })->schema([
                    Select::make('kind')->options(OrderLineKind::class)->default(OrderLineKind::Product)->required()->live()
                        ->afterStateUpdated(function (Set $set): void {
                            $set('variant_id', null);
                            $set('description', null);
                            $set('unit_price', null);
                        }),
                    Select::make('variant_id')->label('Item')->searchable()->live()
                        ->visible(fn (Get $get): bool => $this->lineKind($get('kind')) === OrderLineKind::Product)
                        ->required(fn (Get $get): bool => $this->lineKind($get('kind')) === OrderLineKind::Product)
                        ->getSearchResultsUsing($this->searchVariants(...))
                        ->getOptionLabelUsing(function (mixed $value): ?string {
                            $variant = $this->variantFromState($value);

                            return $variant instanceof Variant ? $this->variantLabel($variant) : null;
                        })->afterStateUpdated(function (mixed $state, Set $set): void {
                            $variant = $this->variantFromState($state);
                            if ($variant instanceof Variant) {
                                $set('unit_price', $this->rupees(app(CounterSalePricing::class)->handle($variant, $this->currentTier())));
                            }
                        }),
                    TextInput::make('description')->maxLength(255)
                        ->visible(fn (Get $get): bool => $this->lineKind($get('kind')) !== OrderLineKind::Product)
                        ->required(fn (Get $get): bool => $this->lineKind($get('kind')) !== OrderLineKind::Product),
                    TextInput::make('qty')->label('Qty')->integer()->default(1)->required()->minValue(1)->maxValue(1000000)->live(),
                    $this->rupeeInput('unit_price')->label('Unit price')->live()
                        ->required(fn (Get $get): bool => $this->lineKind($get('kind')) !== OrderLineKind::Product),
                    TextEntry::make('line_total')->label('Line total')->state(fn (Get $get): string => $this->lineTotal($get)),
                ]),
            TextEntry::make('totals')->state(fn (): string => $this->totalsLabel()),
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

    /** Numeric HTML input without Filament's NumberStateCast, which converts decimals to floats. */
    private function rupeeInput(string $name): TextInput
    {
        return TextInput::make($name)->prefix('Rs')->type('number')->inputMode('decimal')->step('0.01')->rules(['numeric', 'decimal:0,2']);
    }

    private function rupees(int $cents): string
    {
        return str_replace(',', '', Lkr::format($cents));
    }

    private function parseMoney(string $value, string $field): int
    {
        try {
            return Lkr::parse($value);
        } catch (InvalidAmount $exception) {
            throw ValidationException::withMessages([$field => $exception->getMessage()]);
        }
    }

    private function lineKind(mixed $state): ?OrderLineKind
    {
        if ($state instanceof OrderLineKind) {
            return $state;
        }

        return is_string($state) ? OrderLineKind::tryFrom($state) : null;
    }

    private function paymentMethod(mixed $state): ?PaymentMethod
    {
        if ($state instanceof PaymentMethod) {
            return $state;
        }

        return is_string($state) ? PaymentMethod::tryFrom($state) : null;
    }

    /**
     * @param  array<array-key, array<string, mixed>>  $state
     * @return list<CounterSaleLine>
     */
    private function linesFromState(array $state): array
    {
        $lines = [];
        foreach ($state as $key => $line) {
            $kind = $this->lineKind($line['kind'] ?? null);
            if ($kind === null) {
                throw ValidationException::withMessages(['lines.'.$key.'.kind' => 'Choose a line kind.']);
            }
            $price = $line['unit_price'] ?? null;
            $lines[] = new CounterSaleLine(
                $kind,
                isset($line['variant_id']) ? (int) $line['variant_id'] : null,
                $line['description'] ?? null,
                (int) ($line['qty'] ?? 0),
                $price === null || $price === '' ? null : $this->parseMoney((string) $price, 'lines.'.$key.'.unit_price'),
            );
        }

        return $lines;
    }

    /** @param list<CounterSaleLine> $lines */
    private function calculateTotals(Customer $customer, array $lines): OrderTotals
    {
        $totalLines = [];
        foreach (app(PrepareCounterSaleLines::class)->handle($customer, $lines, $this->variantsForLines()) as $line) {
            $totalLines[] = ['kind' => $line['kind'], 'qty' => $line['qty'], 'unitPriceCents' => $line['unitPriceCents']];
        }

        return Totals::calculate($totalLines);
    }

    private function previewTotals(): ?OrderTotals
    {
        if ($this->previewLoaded) {
            return $this->cachedPreview;
        }
        $this->previewLoaded = true;
        try {
            $this->cachedPreview = $this->calculateTotals($this->selectedCustomer() ?? new Customer, $this->linesFromState($this->data['lines'] ?? []));
        } catch (ValidationException|InvalidOrderTotals|AmountTooLarge) {
            $this->cachedPreview = null;
        }

        return $this->cachedPreview;
    }

    private function totalsLabel(): string
    {
        $totals = $this->previewTotals();
        if ($totals === null) {
            return '—';
        }

        return 'Subtotal: '.Lkr::formatWithCurrency($totals->subtotalCents)
            .' | Delivery: '.Lkr::formatWithCurrency($totals->deliveryCents)
            .' | Discount: '.Lkr::formatWithCurrency($totals->discountCents)
            .' | Total: '.Lkr::formatWithCurrency($totals->totalCents);
    }

    private function lineTotal(Get $get): string
    {
        try {
            $lines = $this->linesFromState([['kind' => $get('kind'), 'variant_id' => $get('variant_id'), 'description' => $get('description'), 'qty' => $get('qty'), 'unit_price' => $get('unit_price')]]);
            $line = $lines[0];
            $price = $line->unitPriceCents;
            if ($price === null && $line->variantId !== null) {
                $variant = $this->variantFromState($line->variantId);
                if ($variant !== null) {
                    $price = app(CounterSalePricing::class)->handle($variant, $this->currentTier());
                }
            }

            return $price === null ? '—' : Lkr::formatWithCurrency(Totals::lineTotal($line->kind, $line->qty, $price));
        } catch (ValidationException|InvalidOrderTotals|AmountTooLarge) {
            return '—';
        }
    }

    private function selectedCustomer(): ?Customer
    {
        return $this->resolveCustomer((bool) ($this->data['walk_in'] ?? true), $this->data['customer_id'] ?? null);
    }

    private function resolveCustomer(bool $walkIn, mixed $customerId): ?Customer
    {
        $key = $walkIn ? 'walk_in' : 'customer:'.(is_int($customerId) || is_string($customerId) ? $customerId : '');
        if (! array_key_exists($key, $this->cachedCustomers)) {
            $this->cachedCustomers[$key] = $walkIn
                ? Customer::query()->walkIn()->first()
                : $this->customerQuery()->find(is_int($customerId) || is_string($customerId) ? $customerId : null);
        }

        return $this->cachedCustomers[$key];
    }

    private function currentTier(): PriceTier
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
        foreach ($this->data['lines'] ?? [] as $key => $line) {
            if ($this->lineKind($line['kind'] ?? null) !== OrderLineKind::Product) {
                continue;
            }
            $variant = $this->variantFromState($line['variant_id'] ?? null);
            $price = $line['unit_price'] ?? null;
            if ($variant === null || $price === null || $price === '') {
                continue;
            }
            try {
                $replacement = app(CounterSalePricing::class)->replacementPrice($variant, Lkr::parse((string) $price), $oldTier, $newTier);
            } catch (InvalidAmount) {
                continue;
            }
            if ($replacement !== null) {
                $this->data['lines'][$key]['unit_price'] = $this->rupees($replacement);
            }
        }
    }

    private function invalidateStockOverride(): void
    {
        $this->showStockOverride = false;
        $this->data['stock_override_reason'] = null;
    }

    private function resetPreview(): void
    {
        $this->previewLoaded = false;
        $this->cachedPreview = null;
    }

    private function resetRequestCaches(): void
    {
        $this->cachedCustomers = [];
        $this->cachedLocation = null;
        $this->cachedVariants = null;
        $this->loadedVariantIds = [];
        $this->resetPreview();
    }

    private function defaultLocation(): Location
    {
        return $this->cachedLocation ??= Location::default();
    }

    /** @return Collection<int, Variant> */
    private function variantsForLines(?int $extraId = null): Collection
    {
        $ids = $extraId === null ? [] : [$extraId];
        foreach ($this->data['lines'] ?? [] as $line) {
            if ($this->lineKind($line['kind'] ?? null) === OrderLineKind::Product && isset($line['variant_id'])) {
                $ids[] = (int) $line['variant_id'];
            }
        }
        $missing = [];
        foreach (array_unique($ids) as $id) {
            if (! isset($this->loadedVariantIds[$id])) {
                $missing[] = $id;
                $this->loadedVariantIds[$id] = true;
            }
        }
        $this->cachedVariants ??= new Collection;
        if ($missing !== []) {
            foreach ($this->variantQuery()->whereIn('id', $missing)->get() as $variant) {
                $this->cachedVariants->put($variant->id, $variant);
            }
        }

        return $this->cachedVariants;
    }

    private function variantFromState(mixed $value): ?Variant
    {
        if (! is_int($value) && ! is_string($value)) {
            return null;
        }

        return $this->variantsForLines((int) $value)->get((int) $value);
    }

    /** @return Builder<Customer> */
    private function customerQuery(): Builder
    {
        return Customer::query()->where('is_walk_in', false)->whereNull('merged_into_id')->whereNull('anonymised_at');
    }

    /** @return array<int, string> */
    private function searchCustomers(string $search): array
    {
        return app(SearchCustomers::class)->handle($this->customerQuery(), $search)->orderBy('name')->limit(50)->get()
            ->mapWithKeys(fn (Customer $customer): array => [$customer->id => $this->customerLabel($customer)])->all();
    }

    private function customerLabel(Customer $customer): string
    {
        return $customer->name.' — '.($customer->phone_e164 ?? 'No phone');
    }

    /** @return Builder<Variant> */
    private function variantQuery(): Builder
    {
        return Variant::query()->with(['product', 'stockLevels' => $this->stockAtDefaultLocation(...)])
            ->where('is_active', true);
    }

    /** @param HasMany<StockLevel, Variant> $query */
    private function stockAtDefaultLocation(HasMany $query): void
    {
        $query->where('location_id', $this->defaultLocation()->id);
    }

    /** @return array<int, string> */
    private function searchVariants(string $search): array
    {
        $pattern = '%'.addcslashes(trim($search), '\\%_').'%';

        return $this->variantQuery()->where(fn (Builder $query): Builder => $query->where('sku', 'ilike', $pattern)
            ->orWhereHas('product', fn (Builder $query): Builder => $query->where('title->en', 'ilike', $pattern)))
            ->orderBy('sku')->limit(50)->get()->mapWithKeys(fn (Variant $variant): array => [$variant->id => $this->variantLabel($variant)])->all();
    }

    private function variantLabel(Variant $variant): string
    {
        $level = $variant->stockLevels->first();
        $qty = $level === null ? 0 : $level->qty;

        return $variant->sku.' — '.$variant->displayName().' ('.($variant->track_stock ? 'in stock '.$qty : 'not tracked').')';
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
