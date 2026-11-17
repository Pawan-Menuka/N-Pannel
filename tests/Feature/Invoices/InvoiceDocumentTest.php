<?php

use App\Actions\Invoices\IssueInvoice;
use App\Actions\Invoices\VoidInvoice;
use App\Actions\Orders\RecalculateOrderPayments;
use App\Actions\Payments\RecordPayment;
use App\Enums\OrderLineKind;
use App\Enums\OrderStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Payment;
use App\Models\User;
use App\Settings\BankSettings;
use App\Settings\BusinessSettings;
use App\Settings\DocumentSettings;
use App\Settings\TaxSettings;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00:00', 'Asia/Colombo'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->by = User::factory()->sales()->withAppAuthentication()->create();
    $this->customer = Customer::factory()->create([
        'name' => 'Invoice Buyer', 'company_name' => 'Buyer Company', 'vat_number' => 'BUYER-VAT',
        'email' => 'buyer@example.test', 'address_line1' => 'Buyer Street', 'address_line2' => 'Floor 2', 'city' => 'Kandy',
    ]);
    $this->order = Order::factory()->create([
        'customer_id' => $this->customer->id, 'created_by_id' => $this->by->id, 'status' => OrderStatus::Confirmed,
        'subtotal_cents' => 120000, 'delivery_cents' => 10000, 'discount_cents' => 12000, 'total_cents' => 118000,
    ]);
    OrderLine::factory()->create(['order_id' => $this->order->id, 'description' => 'Original panels', 'sku' => 'PANEL-SNAPSHOT', 'unit_price_cents' => 120000, 'line_total_cents' => 120000]);
    OrderLine::factory()->create(['order_id' => $this->order->id, 'kind' => OrderLineKind::Discount, 'description' => 'Discount', 'unit_price_cents' => 12000, 'line_total_cents' => -12000, 'sort_order' => 1]);
    $business = app(BusinessSettings::class);
    $business->name = 'Invoice Seller';
    $business->address = 'Seller Street';
    $business->phone = '+94112345678';
    $business->email = 'seller@example.test';
    $business->save();
    $documents = app(DocumentSettings::class);
    $documents->invoice_footer = 'Thank you for your business.';
    $documents->show_bank_details_on_invoice = true;
    $documents->save();
    $bank = app(BankSettings::class);
    $bank->account_name = 'Seller Account';
    $bank->bank_name = 'Test Bank';
    $bank->branch = 'Colombo Branch';
    $bank->account_number = '123456789';
    $bank->save();
    $tax = app(TaxSettings::class);
    $tax->vat_number = 'SELLER-VAT';
    $tax->vat_rate_bp = 1800;
    $tax->save();
    $this->issued = app(IssueInvoice::class)->handle($this->order, null, $this->by);
});

it('prints invoice snapshots with VAT payments refunds balances bank details and private headers', function () {
    $payment = app(RecordPayment::class)->handle($this->order, PaymentMethod::Cash, 50000, null, null, $this->by);
    Payment::factory()->create([
        'order_id' => $this->order->id, 'kind' => PaymentKind::Refund, 'status' => PaymentStatus::Confirmed,
        'amount_cents' => 10000, 'confirmed_at' => now(), 'created_by_id' => $this->by->id,
        'confirmed_by_id' => $this->by->id, 'note' => 'Historical refund',
    ]);
    DB::transaction(function (): void {
        $order = Order::query()->lockForUpdate()->findOrFail($this->order->id);
        app(RecalculateOrderPayments::class)->handle($order);
    });
    Payment::factory()->create(['order_id' => $this->order->id, 'status' => PaymentStatus::Pending, 'reference' => 'PENDING-HIDDEN']);
    $response = $this->get($this->issued->url)->assertOk()->assertViewIs('documents.invoice')
        ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertSee('TAX INVOICE')->assertSee('SELLER-VAT')->assertSee('BUYER-VAT')->assertSee('Invoice Buyer')->assertSee('Buyer Company')
        ->assertSee('Buyer Street')->assertSee('Floor 2')->assertSee('Kandy')->assertSee('buyer@example.test')
        ->assertSee('Invoice Seller')->assertSee('Seller Street')->assertSee('seller@example.test')->assertSee('+94112345678')
        ->assertSee('PANEL-SNAPSHOT')->assertSee('Original panels')->assertSee('Rs -120.00')->assertSee('Rs 1,180.00')
        ->assertSee('VAT included at 18%: Rs 180.00')->assertSee($payment->receipt_number)->assertSee('Rs -100.00')
        ->assertSee('Amount paid')->assertSee('Rs 400.00')->assertSee('Balance due')->assertSee('Rs 780.00')
        ->assertSee('123456789')->assertSee('Seller Account')->assertSee('Test Bank')->assertSee('Colombo Branch')
        ->assertSee('Thank you for your business.')->assertSee('window.print()', false)->assertSee('@media print', false)
        ->assertSee('<meta name="robots" content="noindex">', false)->assertDontSee('PENDING-HIDDEN');
    expect($response->viewData('document')->payments)->toHaveCount(2);
});

it('renders plain invoices without a seller VAT line', function () {
    app(VoidInvoice::class)->handle($this->issued->invoice, 'Change tax setup', $this->by);
    $tax = app(TaxSettings::class);
    $tax->vat_number = null;
    $tax->vat_rate_bp = null;
    $tax->save();
    $plain = app(IssueInvoice::class)->handle($this->order, null, $this->by);
    $this->get($plain->url)->assertOk()->assertSee('INVOICE')->assertDontSee('TAX INVOICE')->assertDontSee('VAT included')->assertDontSee('SELLER-VAT');
});

it('uses frozen tax rates and buyer details after customer and settings edits', function () {
    $this->customer->update(['name' => 'CHANGED BUYER', 'company_name' => 'CHANGED COMPANY', 'vat_number' => 'CHANGED-VAT', 'address_line1' => 'CHANGED ADDRESS']);
    $tax = app(TaxSettings::class);
    $tax->vat_rate_bp = 1250;
    $tax->vat_number = 'CHANGED SELLER VAT';
    $tax->save();
    $this->get($this->issued->url)->assertOk()->assertSee('Invoice Buyer')->assertSee('BUYER-VAT')->assertSee('SELLER-VAT')
        ->assertSee('VAT included at 18%')->assertDontSee('CHANGED');
});

it('formats fractional VAT rates', function () {
    app(VoidInvoice::class)->handle($this->issued->invoice, 'Reissue', $this->by);
    $tax = app(TaxSettings::class);
    $tax->vat_rate_bp = 1250;
    $tax->save();
    $issued = app(IssueInvoice::class)->handle($this->order, null, $this->by);
    $this->get($issued->url)->assertOk()->assertSee('VAT included at 12.5%');
});

it('hides bank details unless enabled and an account number exists', function (bool $enabled, string $number) {
    $settings = app(DocumentSettings::class);
    $settings->show_bank_details_on_invoice = $enabled;
    $settings->save();
    $bank = app(BankSettings::class);
    $bank->account_number = $number;
    $bank->save();
    $this->get($this->issued->url)->assertOk()->assertDontSee('Test Bank')->assertDontSee('Seller Account');
})->with([[false, '123456789'], [true, ''], [true, '   ']]);

it('omits empty seller details and escapes invoice content', function () {
    $business = app(BusinessSettings::class);
    $business->address = '';
    $business->phone = '';
    $business->email = '';
    $business->save();
    $settings = app(DocumentSettings::class);
    $settings->invoice_footer = '<script>alert(1)</script>';
    $settings->save();
    $this->get($this->issued->url)->assertOk()->assertDontSee('<p></p>', false)->assertDontSee('<p> · </p>', false)
        ->assertSee('<script>alert(1)</script>')->assertDontSee('<script>alert(1)</script>', false);
});

it('prints void invoices only through the authorised admin route with their reason', function () {
    app(VoidInvoice::class)->handle($this->issued->invoice, 'Wrong buyer', $this->by);
    $this->actingAs($this->by);
    $this->get(route('filament.admin.invoices.print', ['invoice' => $this->issued->invoice]))->assertOk()->assertSee('VOID — Wrong buyer');
    $this->get($this->issued->url)->assertNotFound();
});

it('prints one confirmed payment receipt with current order totals and active invoice', function () {
    $payment = app(RecordPayment::class)->handle($this->order, PaymentMethod::BankTransfer, 50000, 'BANK-REFERENCE', null, $this->by);
    app(RecordPayment::class)->handle($this->order, PaymentMethod::Cash, 10000, null, null, $this->by);
    $this->actingAs($this->by);
    $this->get(route('filament.admin.payments.receipt', ['payment' => $payment]))->assertOk()->assertViewIs('documents.receipt')
        ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertSee('RECEIPT')->assertSee($payment->receipt_number)->assertSee('Invoice Buyer')->assertSee($this->order->reference)
        ->assertSee($this->issued->invoice->number)->assertSee('BANK-REFERENCE')->assertSee(PaymentMethod::BankTransfer->getLabel())
        ->assertSee('Rs 500.00')->assertSee('Rs 1,180.00')->assertSee('Rs 600.00')->assertSee('Rs 580.00')->assertSee('Thank you for your business.');
});

it('refuses receipts for refunds pending payments or missing receipt numbers', function (PaymentKind $kind, PaymentStatus $status, ?string $number) {
    $payment = Payment::factory()->create(['order_id' => $this->order->id, 'kind' => $kind, 'status' => $status, 'receipt_number' => $number]);
    $this->actingAs($this->by);
    $this->get(route('filament.admin.payments.receipt', ['payment' => $payment]))->assertNotFound();
})->with([[PaymentKind::Refund, PaymentStatus::Confirmed, 'RCP-2026-99999'], [PaymentKind::Payment, PaymentStatus::Pending, 'RCP-2026-99999'], [PaymentKind::Payment, PaymentStatus::Confirmed, null]]);

it('protects both admin document routes by authentication role and MFA', function (string $role) {
    $payment = app(RecordPayment::class)->handle($this->order, PaymentMethod::Cash, 10000, null, null, $this->by);
    if ($role !== 'guest') {
        $this->actingAs($role === 'no-mfa' ? User::factory()->sales()->create() : User::factory()->withAppAuthentication()->create(['role' => $role]));
    }
    foreach ([route('filament.admin.invoices.print', ['invoice' => $this->issued->invoice]), route('filament.admin.payments.receipt', ['payment' => $payment])] as $url) {
        $response = $this->get($url);
        match ($role) {
            'guest' => $response->assertRedirect(route('filament.admin.auth.login')),
            'stock' => $response->assertForbidden(),
            'no-mfa' => $response->assertRedirect(Filament::getPanel('admin')->getSetUpRequiredMultiFactorAuthenticationUrl()),
            default => $response->assertOk(),
        };
    }
})->with(['guest', 'stock', 'sales', 'admin', 'no-mfa']);

it('omits the invoice number on a receipt when no active invoice remains', function () {
    $payment = app(RecordPayment::class)->handle($this->order, PaymentMethod::Cash, 10000, null, null, $this->by);
    app(VoidInvoice::class)->handle($this->issued->invoice, 'Wrong buyer', $this->by);
    $this->actingAs($this->by);
    $this->get(route('filament.admin.payments.receipt', ['payment' => $payment]))->assertOk()->assertDontSee($this->issued->invoice->number);
});
