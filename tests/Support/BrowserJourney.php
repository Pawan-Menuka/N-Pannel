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
use PHPUnit\Framework\ExpectationFailedException;
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
        self::waitForPageReady($page);
        $page->fill('input[id="form.email"]', $user->email)
            ->fill('input[id="form.password"]', 'password')
            ->click(self::button('Sign in'));
        $codeInput = 'input[autocomplete="one-time-code"]';
        $page->page()->locator($codeInput)->waitFor(['state' => 'visible']);
        $page->assertVisible($codeInput);

        $secret = $user->getAppAuthenticationSecret();
        assert(is_string($secret));
        $code = app(Google2FA::class)->getCurrentOtp($secret);

        // Filament submits the MFA form automatically after all six digits.
        $page->fill($codeInput, $code)->assertPathIs('/admin');
        self::waitForPageReady($page);

        return $page;
    }

    private static function waitForPageReady(PendingAwaitablePage $page, bool $filament = true): void
    {
        // The plugin's load-state call does not consume the Playwright response.
        $page->assertScript('document.readyState === "complete"');

        if ($filament) {
            $page->assertScript(<<<'JS'
                function () {
                    return window.Livewire?.initialRenderIsFinished === true
                        && !document.querySelector('[data-loading]')
                        && !Array.from(document.querySelectorAll('[wire\\:loading], [wire\\:loading\\.delay]'))
                            .some(element => element.getClientRects().length > 0 && getComputedStyle(element).visibility !== 'hidden');
                }
                JS);
        }
    }

    private function navigate(string $url, bool $filament = true): void
    {
        $this->page->navigate($url);
        self::waitForPageReady($this->page, $filament);
    }

    private function waitAfterRedirect(string $previousUrl): void
    {
        $path = parse_url($previousUrl, PHP_URL_PATH);
        assert(is_string($path));
        $this->page->assertPathIsNot($path);
        self::waitForPageReady($this->page);
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
        $button = self::selectButton($name);
        $this->page->page()->locator($button)->waitFor(['state' => 'visible']);
        $this->page->assertVisible($button)->click($button)->assertAttribute($button, 'aria-expanded', 'true');
        $dropdown = $this->page->attribute($button, 'aria-controls');
        expect($dropdown)->toBeString()->not->toBeEmpty();

        $this->page->fill('input[role="combobox"][aria-controls="'.$dropdown.'"]:visible', $search)
            ->click('[id="'.$dropdown.'"] >> '.Selector::getByRoleSelector('option', ['name' => $search]))
            ->assertAttribute($button, 'aria-expanded', 'false')
            ->assertNotPresent('[data-loading]');
    }

    private static function selectButton(string $name): string
    {
        return self::field($name, 'button[aria-haspopup="listbox"]');
    }

    private function addQuoteLine(): void
    {
        $browser = $this->page->page();
        $rows = $browser->locator('.fi-fo-repeater-items > [x-sortable-item]');
        $rowCount = $rows->count();
        $add = self::button('Add to lines');
        $this->page->click($add);

        try {
            $browser->locator(self::selectButton('variant_id'))->waitFor(['state' => 'visible', 'timeout' => 2500]);
        } catch (ExpectationFailedException $exception) {
            if (! str_contains($exception->getMessage(), 'Timeout 2500ms exceeded')) {
                throw $exception;
            }

            self::waitForPageReady($this->page);
            if ($rows->count() === $rowCount) {
                $this->page->click($add);
            }

            $this->page->assertVisible(self::selectButton('variant_id'));
        }
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

    private function assertUpdatedText(string $text, bool $exact = false): void
    {
        $this->page->page()->locator(Selector::getByTextSelector($text, $exact).' >> visible=true')->first()
            ->waitFor(['state' => 'visible']);
        $this->page->assertSee($text);
    }

    private function assertUpdatedValue(string $field, string $value): void
    {
        $this->page->page()->locator($field)->waitFor(['state' => 'visible']);
        $this->page->assertValue($field, $value);
    }

    public function submitModal(string $label): void
    {
        $button = self::modalButton($label);
        $this->page->assertVisible($button)->click($button);
    }

    public function receive(): GoodsReceipt
    {
        $this->navigate(SupplierResource::getUrl('create'));
        $createUrl = $this->page->url();
        $this->page->fill(self::field('name'), 'Journey Supplier')
            ->click(self::button('Create'));
        $this->waitAfterRedirect($createUrl);
        $this->assertUpdatedText('Created', exact: true);

        $this->navigate(GoodsReceiptResource::getUrl('create'));
        $this->pick('supplier_id', 'Journey Supplier');
        $this->page->click(self::button('Create'))->assertPathContains('/edit');
        self::waitForPageReady($this->page);
        $draftUrl = $this->page->url();
        $this->page->click(self::button('New goods receipt line'));
        $this->pick('variant_id', 'JOURNEY-PANEL');
        $this->page->fill(self::field('qty'), '50')->fill(self::field('unit_cost_cents'), '50.00');
        $this->submitModal('Create');
        $this->page->assertNotPresent(self::modal());
        $this->assertUpdatedText('JOURNEY-PANEL');
        $this->page->click(self::button('Post'));
        $this->submitModal('Confirm');
        $this->waitAfterRedirect($draftUrl);
        $this->page->assertNotPresent(self::modal());
        $this->assertUpdatedText('Goods received posted', exact: true);
        $this->assertUpdatedText('GRN-');

        return GoodsReceipt::query()->sole();
    }

    public function sell(): Order
    {
        $this->navigate(OrderResource::getUrl('index'));
        $ordersUrl = $this->page->url();
        $this->page->click('New sale');
        $this->waitAfterRedirect($ordersUrl);
        $saleUrl = $this->page->url();
        $this->page->click(self::toggle('walk_in'))
            ->assertVisible(self::field('customer_id', 'button'));
        $this->pick('customer_id', $this->customer->name);
        $this->pick('variant_id', 'JOURNEY-PANEL');
        $this->assertUpdatedValue(self::field('unit_price'), '100.00');
        $this->page->fill(self::field('qty'), '12');
        $this->assertUpdatedText('Total: Rs 1,200.00');
        $this->page->fill(self::field('payment.amount'), '200.00')->click(self::button('Complete sale'));
        $this->waitAfterRedirect($saleUrl);
        $this->assertUpdatedText('RCP-');
        $this->assertUpdatedText('Confirmed', exact: true);

        return Order::query()->where('customer_id', $this->customer->id)->sole();
    }

    public function acceptTradeQuote(): Order
    {
        $this->navigate(OrderResource::getUrl('index'));
        $this->page->click(self::button('New quote'));
        $this->pick('customer_id', $this->contractor->name);
        $this->submitModal('Submit');
        $this->page->assertPathContains('/quote');
        self::waitForPageReady($this->page);
        $this->addQuoteLine();
        $this->pick('variant_id', 'JOURNEY-PANEL');
        $this->assertUpdatedValue(self::field('unit_price'), '80.00');
        $this->page->fill(self::field('qty'), '30');
        $this->assertUpdatedText('Total: Rs 2,400.00');
        $this->page->fill(self::field('valid_until', 'input[type="date"]'), now('Asia/Colombo')->addDays(7)->toDateString())
            ->click(self::button('Save'));
        $this->assertUpdatedText('Quote saved', exact: true);
        $this->page->click(self::button('Get quote link'));
        $this->assertUpdatedText('Quote link', exact: true);
        $this->page->assertVisible(self::field('url'));
        $url = $this->page->value(self::field('url'));
        expect($url)->toBeString()->not->toBeEmpty();
        $this->page->click(self::modalButton('Close'))->assertNotPresent(self::modal());
        $editUrl = $this->page->url();
        $this->navigate($url, filament: false);
        $this->page->assertSee($this->contractor->name)->assertSee('JOURNEY-PANEL');
        $quote = Order::query()->where('customer_id', $this->contractor->id)->sole();
        $this->navigate($editUrl);
        $this->page->click(self::button('Mark accepted'));
        $this->submitModal('Submit');
        $this->waitAfterRedirect($editUrl);
        $this->page->assertNotPresent(self::modal());
        $this->assertUpdatedText('Quote '.$quote->reference.' accepted', exact: true);
        $this->assertUpdatedText('Confirmed', exact: true);

        return $quote->refresh();
    }

    public function invoice(Order $sale): void
    {
        $this->navigate(OrderResource::getUrl('view', ['record' => $sale]));
        $orderUrl = $this->page->url();
        $this->page->click(self::button('Record payment'));
        $this->assertUpdatedValue(self::field('amount'), '1000.00');
        $this->submitModal('Submit');
        $this->page->assertNotPresent(self::modal());
        $this->assertUpdatedText('Payment recorded');
        $this->page->click(self::button('Issue invoice'));
        $this->submitModal('Submit');
        $this->assertUpdatedText('Invoice link', exact: true);
        $this->page->click(self::modalButton('Close'))->assertNotPresent(self::modal())
            ->click('View invoice');
        $this->waitAfterRedirect($orderUrl);
        $this->assertUpdatedText('INV-');
        $invoice = $sale->refresh()->activeInvoice();
        expect($invoice)->not->toBeNull();
        $this->navigate(route('filament.admin.invoices.print', ['invoice' => $invoice]), filament: false);
        $this->page->assertSee($invoice->number)->assertSee('INVOICE')->assertSee('JOURNEY-PANEL');
    }

    public function returnPanel(Order $sale, bool $restock): void
    {
        $nextNumber = 'CRN-'.now('Asia/Colombo')->year.'-'.str_pad((string) ($sale->salesReturns()->count() + 1), 5, '0', STR_PAD_LEFT);
        $this->navigate(OrderResource::getUrl('view', ['record' => $sale]));
        $this->page->click(self::button('Record return'))
            ->click(self::toggle('refund'))
            ->assertVisible(self::field('refund_amount'));
        $this->assertUpdatedText('Up to Rs 0.00 can be refunded', exact: true);
        $this->page->fill(self::field('qty'), '1');
        $this->assertUpdatedText('Up to Rs 100.00 can be refunded', exact: true);
        if (! $restock) {
            $this->page->click(self::toggle('restock'))->assertAttribute(self::toggle('restock'), 'aria-checked', 'false');
        }
        $this->page->fill(self::field('reason', 'textarea'), $restock ? 'Unused panel' : 'Damaged panel')
            ->fill(self::field('refund_amount'), '100.00');
        $this->submitModal('Submit');
        $this->page->assertNotPresent(self::modal());
        $this->assertUpdatedText('Return recorded');
        $this->assertUpdatedText($nextNumber);
    }
}
