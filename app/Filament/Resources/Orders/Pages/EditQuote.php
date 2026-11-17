<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Actions\Orders\SaveQuoteLines;
use App\Domain\Order\Totals;
use App\Enums\OrderStatus;
use App\Enums\PriceTier;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Support\HasQuoteActions;
use App\Filament\Support\InteractsWithOrderLines;
use App\Filament\Support\PurchasingValidation;
use App\Models\Customer;
use App\Models\Order;
use App\Settings\QuoteSettings;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/** @property Schema $form */
class EditQuote extends Page
{
    use HasQuoteActions;
    use InteractsWithOrderLines;

    protected static string $resource = OrderResource::class;

    #[Locked]
    public Model|int|string|null $record;

    /** @var array<string, mixed> */
    public array $data = [];

    private ?Customer $cachedQuoteCustomer = null;

    /** This page handles denied access with a warning and redirect in mount/hydrate. */
    public static function authorizeResourceAccess(): void
    {
        abort_unless(Filament::auth()->check(), 403);
    }

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        if (! $this->ensureEditable()) {
            return;
        }
        $lines = [];
        foreach ($this->getRecord()->lines()->orderBy('sort_order')->orderBy('id')->get() as $line) {
            $lines[] = ['kind' => $line->kind->value, 'variant_id' => $line->variant_id, 'description' => $line->description, 'qty' => $line->qty, 'unit_price' => $this->rupees($line->unit_price_cents)];
        }
        $this->form->fill([
            'lines' => $lines, 'required_advance' => $this->getRecord()->required_advance_cents === null ? null : $this->rupees($this->getRecord()->required_advance_cents),
            'exclusions' => $this->getRecord()->exclusions, 'valid_until' => $this->getRecord()->valid_until?->copy()->setTimezone('Asia/Colombo')->format('Y-m-d'),
        ]);
    }

    public function hydrate(): void
    {
        $this->ensureEditable();
    }

    public function updated(string $property): void
    {
        $this->resetOrderLineCaches();
    }

    public function getTitle(): string
    {
        return 'Quote '.$this->getRecord()->reference;
    }

    public function getRecord(): Order
    {
        abort_unless($this->record instanceof Order, 404);

        return $this->record;
    }

    protected function quoteRecord(): Order
    {
        return $this->getRecord();
    }

    protected function getHeaderActions(): array
    {
        if ($this->getRecord()->status !== OrderStatus::Quoted || ! $this->quoteActor()->can('update', $this->getRecord())) {
            return [];
        }

        return $this->quoteActions();
    }

    public function content(Schema $schema): Schema
    {
        if ($this->getRecord()->status !== OrderStatus::Quoted || ! $this->quoteActor()->can('update', $this->getRecord())) {
            return $schema->components([]);
        }

        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])->id('form')->livewireSubmitHandler('saveQuote')
                ->footer([Actions::make([Action::make('saveQuote')->label('Save')->submit('saveQuote')])]),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $percent = app(QuoteSettings::class)->default_advance_pct;

        return $schema->statePath('data')->components([
            Section::make('Customer')->schema([
                TextEntry::make('customer_name')->label('Customer')->state(fn (): string => $this->getRecord()->customer_name),
                TextEntry::make('phone')->state(fn (): ?string => $this->getRecord()->phone_e164),
                TextEntry::make('price_tier')->state(fn (): string => $this->currentTier()->getLabel()),
            ]),
            $this->orderLinesRepeater(), $this->orderTotalsEntry(),
            $this->rupeeInput('required_advance')->label('Required advance')
                ->hintAction(Action::make('useDefaultAdvance')->label('Use default ('.$percent.'%)')->action(function (Set $set) use ($percent): void {
                    $totals = $this->previewTotals();
                    if ($totals !== null) {
                        $set('required_advance', $this->rupees(Totals::percentOf($totals->totalCents, $percent)));
                    }
                })),
            Textarea::make('exclusions')->maxLength(2000),
            DatePicker::make('valid_until')->label('Valid until')->required()->minDate(fn (): string => now('Asia/Colombo')->toDateString()),
        ]);
    }

    public function saveQuote(): void
    {
        if (! $this->ensureEditable()) {
            return;
        }
        $this->persistQuote();
        Notification::make()->success()->title('Quote saved')->send();
    }

    protected function saveBeforeSending(): void
    {
        if (! $this->ensureEditable()) {
            throw new Halt;
        }
        $this->persistQuote();
    }

    private function persistQuote(): void
    {
        $state = $this->form->getState();
        try {
            $advance = $state['required_advance'] ?? null;
            $this->record = app(SaveQuoteLines::class)->handle(
                $this->getRecord(), $this->linesFromState($state['lines'] ?? []),
                $advance === null || $advance === '' ? null : $this->parseMoney((string) $advance, 'required_advance'),
                $state['exclusions'] ?? null, CarbonImmutable::parse($state['valid_until'], 'Asia/Colombo'), $this->quoteActor(),
            );
        } catch (ValidationException $exception) {
            $errors = [];
            foreach ($exception->errors() as $key => $messages) {
                $field = $key === 'required_advance_cents' || ($key === 'lines' && str_contains(implode(' ', $messages), 'required advance')) ? 'required_advance' : $key;
                $errors[$field] = $messages;
            }
            throw PurchasingValidation::forForm(ValidationException::withMessages($errors));
        }
        $this->resetOrderLineCaches();
    }

    private function ensureEditable(): bool
    {
        $this->getRecord()->refresh();
        if ($this->getRecord()->status === OrderStatus::Quoted && $this->quoteActor()->can('update', $this->getRecord())) {
            return true;
        }
        Notification::make()->warning()->title('This quote cannot be edited.')->send();
        $this->redirect(OrderResource::getUrl('view', ['record' => $this->getRecord()]));

        return false;
    }

    protected function selectedCustomer(): ?Customer
    {
        return $this->cachedQuoteCustomer ??= $this->getRecord()->customer;
    }

    protected function currentTier(): PriceTier
    {
        $customer = $this->selectedCustomer();

        return $customer === null ? $this->getRecord()->price_tier : $customer->price_tier;
    }

    protected function allowsEmptyOrderLines(): bool
    {
        return true;
    }
}
