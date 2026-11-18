<?php

namespace Tests\Support;

use App\Enums\PriceTier;
use App\Filament\Resources\GoodsReceipts\GoodsReceiptResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Models\Customer;
use App\Models\GoodsReceipt;
use App\Models\Location;
use App\Models\Order;
use App\Models\User;
use App\Models\Variant;
use Pest\Browser\Api\PendingAwaitablePage;
use Pest\Browser\Support\Selector;
use PragmaRX\Google2FAQRCode\Google2FA;

final class BrowserJourney
{
    public PendingAwaitablePage $page;

    public Variant $variant;

    public Customer $customer;

    public Customer $contractor;

    public Location $location;

    public function __construct()
    {
        $this->location = Location::factory()->create(['is_default' => true]);
        $this->variant = Variant::factory()->create([
            'sku' => 'JOURNEY-PANEL', 'price_cents' => 10000,
            'trade_price_cents' => 8000, 'last_cost_cents' => null,
        ]);
        $this->customer = Customer::factory()->create(['name' => 'Journey Retail Customer']);
        $this->contractor = Customer::factory()->create(['name' => 'Journey Trade Customer', 'price_tier' => PriceTier::Trade]);
        $this->page = self::signIn(User::factory()->admin()->withAppAuthentication()->create());
    }

    public static function signIn(User $user): PendingAwaitablePage
    {
        $page = visit('/admin/login');
        $page->fill('input[id="form.email"]', $user->email)
            ->fill('input[id="form.password"]', 'password')
            ->click(self::button('Sign in'))
            ->assertVisible('input[autocomplete="one-time-code"]');

        $secret = $user->getAppAuthenticationSecret();
        assert(is_string($secret));
        $code = app(Google2FA::class)->getCurrentOtp($secret);

        // Filament submits the MFA form automatically after all six digits.
        $page->fill('input[autocomplete="one-time-code"]', $code)->assertPathIs('/admin');

        return $page;
    }

    /** Field ID suffixes survive schema prefixes and repeater UUIDs. */
    public static function field(string $name, string $element = 'input'): string
    {
        return $element.'[id$=".'.$name.'"]:visible';
    }

    public static function toggle(string $name): string
    {
        return self::field($name, 'button[role="switch"]');
    }

    public static function button(string $label): string
    {
        return 'internal:role=button[name='.Selector::escapeForTextSelector($label, true).']';
    }

    public function pick(string $name, string $search): void
    {
        $button = self::field($name, 'button[aria-haspopup="listbox"]');
        $this->page->assertVisible($button)->click($button)->assertAttribute($button, 'aria-expanded', 'true');
        $dropdown = $this->page->attribute($button, 'aria-controls');
        expect($dropdown)->toBeString()->not->toBeEmpty();

        $this->page->fill('input[role="combobox"][aria-controls="'.$dropdown.'"]:visible', $search)
            ->click('[id="'.$dropdown.'"] >> '.Selector::getByRoleSelector('option', ['name' => $search]))
            ->assertAttribute($button, 'aria-expanded', 'false')
            ->assertNotPresent('[data-loading]');
    }

    /** Both modal roles wrap a visible window inside a fixed-position container. */
    public static function modal(): string
    {
        return '.fi-modal-open > .fi-modal-window-ctn > .fi-modal-window:visible';
    }

    public static function modalButton(string $label): string
    {
        return self::modal().' > .fi-modal-footer >> '.self::button($label);
    }

    public function submitModal(string $label): void
    {
        $button = self::modalButton($label);
        $this->page->assertVisible($button)->click($button);
    }

    public function receive(): GoodsReceipt
    {
        $this->page->navigate(SupplierResource::getUrl('create'))
            ->fill(self::field('name'), 'Journey Supplier')
            ->click(self::button('Create'))
            ->assertSee('Created');

        $this->page->navigate(GoodsReceiptResource::getUrl('create'));
        $this->pick('supplier_id', 'Journey Supplier');
        $this->page->click(self::button('Create'))->assertPathContains('/edit');
        $this->page->click(self::button('New goods receipt line'));
        $this->pick('variant_id', 'JOURNEY-PANEL');
        $this->page->fill(self::field('qty'), '50')->fill(self::field('unit_cost_cents'), '50.00');
        $this->submitModal('Create');
        $this->page->assertNotPresent(self::modal())->assertSee('JOURNEY-PANEL')->click(self::button('Post'));
        $this->submitModal('Confirm');
        $this->page->assertNotPresent(self::modal())->assertSee('Goods received posted')->assertSee('GRN-');

        return GoodsReceipt::query()->sole();
    }

    public function sell(): Order
    {
        $this->page->navigate(OrderResource::getUrl('index'))->click('New sale')
            ->click(self::toggle('walk_in'))
            ->assertVisible(self::field('customer_id', 'button'));
        $this->pick('customer_id', $this->customer->name);
        $this->pick('variant_id', 'JOURNEY-PANEL');
        $this->page->assertValue(self::field('unit_price'), '100.00')
            ->fill(self::field('qty'), '12')
            ->assertSee('Total: Rs 1,200.00')
            ->fill(self::field('payment.amount'), '200.00')
            ->click(self::button('Complete sale'))
            ->assertSee('RCP-')->assertSee('Confirmed');

        return Order::query()->where('customer_id', $this->customer->id)->sole();
    }

    public function acceptTradeQuote(): Order
    {
        $this->page->navigate(OrderResource::getUrl('index'))->click(self::button('New quote'));
        $this->pick('customer_id', $this->contractor->name);
        $this->submitModal('Submit');
        $this->page->assertPathContains('/quote')->click(self::button('Add to lines'));
        $this->pick('variant_id', 'JOURNEY-PANEL');
        $this->page->assertValue(self::field('unit_price'), '80.00')
            ->fill(self::field('qty'), '30')
            ->assertSee('Total: Rs 2,400.00')
            ->fill(self::field('valid_until', 'input[type="date"]'), now('Asia/Colombo')->addDays(7)->toDateString())
            ->click(self::button('Save'))->assertSee('Quote saved')
            ->click(self::button('Get quote link'))->assertSee('Quote link');
        $this->page->assertVisible(self::field('url'));
        $url = $this->page->value(self::field('url'));
        expect($url)->toBeString()->not->toBeEmpty();
        $this->page->click(self::modalButton('Close'))->assertNotPresent(self::modal());
        $editUrl = $this->page->url();
        $this->page->navigate($url)->assertSee($this->contractor->name)->assertSee('JOURNEY-PANEL');
        $quote = Order::query()->where('customer_id', $this->contractor->id)->sole();
        $this->page->navigate($editUrl)->click(self::button('Mark accepted'));
        $this->submitModal('Submit');
        $this->page->assertNotPresent(self::modal())->assertSee('Quote '.$quote->reference.' accepted')->assertSee('Confirmed');

        return $quote->refresh();
    }

    public function invoice(Order $sale): void
    {
        $this->page->navigate(OrderResource::getUrl('view', ['record' => $sale]))
            ->click(self::button('Record payment'))
            ->assertValue(self::field('amount'), '1000.00');
        $this->submitModal('Submit');
        $this->page->assertNotPresent(self::modal())->assertSee('Payment recorded')->click(self::button('Issue invoice'));
        $this->submitModal('Submit');
        $this->page->assertSee('Invoice link')->click(self::modalButton('Close'))->assertNotPresent(self::modal())
            ->click('View invoice')->assertSee('INV-');
        $invoice = $sale->refresh()->activeInvoice();
        expect($invoice)->not->toBeNull();
        $this->page->navigate(route('filament.admin.invoices.print', ['invoice' => $invoice]))
            ->assertSee($invoice->number)->assertSee('INVOICE')->assertSee('JOURNEY-PANEL');
    }

    public function returnPanel(Order $sale, bool $restock): void
    {
        $nextNumber = 'CRN-'.now('Asia/Colombo')->year.'-'.str_pad((string) ($sale->salesReturns()->count() + 1), 5, '0', STR_PAD_LEFT);
        $this->page->navigate(OrderResource::getUrl('view', ['record' => $sale]))
            ->click(self::button('Record return'))
            ->fill(self::field('qty'), '1');
        if (! $restock) {
            $this->page->click(self::toggle('restock'))->assertAttribute(self::toggle('restock'), 'aria-checked', 'false');
        }
        $this->page->fill(self::field('reason', 'textarea'), $restock ? 'Unused panel' : 'Damaged panel')
            ->click(self::toggle('refund'))
            ->assertVisible(self::field('refund_amount'))
            ->assertSee('Up to Rs 100.00 can be refunded')
            ->fill(self::field('refund_amount'), '100.00');
        $this->submitModal('Submit');
        $this->page->assertNotPresent(self::modal())->assertSee('Return recorded')->assertSee($nextNumber);
    }
}
