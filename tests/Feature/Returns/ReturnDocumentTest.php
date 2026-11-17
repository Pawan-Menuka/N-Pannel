<?php

use App\Actions\Invoices\IssueInvoice;
use App\Actions\Returns\BuildCreditNoteDocument;
use App\Actions\Returns\CreateReturn;
use App\Actions\Returns\ReturnLineInput;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\User;
use App\Settings\BusinessSettings;
use App\Settings\DocumentSettings;
use App\Settings\TaxSettings;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-18 10:00:00', 'Asia/Colombo'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->by = User::factory()->sales()->withAppAuthentication()->create();
    $this->order = Order::factory()->create(['status' => OrderStatus::Confirmed, 'total_cents' => 11800, 'subtotal_cents' => 11800]);
    $this->line = OrderLine::factory()->for($this->order)->create(['sku' => 'PRINT-PANEL', 'description' => 'Printed panel', 'qty' => 2, 'unit_price_cents' => 5900, 'line_total_cents' => 11800]);
    $business = app(BusinessSettings::class);
    $business->name = 'Panel Seller';
    $business->address = 'Seller Street';
    $business->save();
    $documents = app(DocumentSettings::class);
    $documents->invoice_footer = 'Invoice footer on credit note';
    $documents->save();
});

it('prints credit note snapshots with the original invoice tax registration', function (bool $taxInvoice) {
    $tax = app(TaxSettings::class);
    $tax->vat_number = $taxInvoice ? 'SELLER-VAT' : null;
    $tax->vat_rate_bp = $taxInvoice ? 1800 : null;
    $tax->save();
    $issued = app(IssueInvoice::class)->handle($this->order, null, $this->by);
    $return = app(CreateReturn::class)->handle($this->order, [new ReturnLineInput($this->line->id, 1, 5900, false)], 'One damaged panel', null, null, $this->by);
    $credit = $return->creditNote;
    $this->line->update(['description' => 'Edited later']);
    $this->order->customer->update(['name' => 'Edited buyer']);
    $tax->vat_number = 'CHANGED-VAT';
    $tax->vat_rate_bp = 500;
    $tax->save();

    $response = $this->actingAs($this->by)->get(route('filament.admin.credit-notes.print', ['creditNote' => $credit]))
        ->assertOk()->assertViewIs('documents.credit-note')->assertHeader('Cache-Control', 'no-store, private')
        ->assertSee('CREDIT NOTE')->assertSee('CRN-2026-00001')->assertSee('18 Nov 2026')
        ->assertSee('Against invoice '.$issued->invoice->number)->assertSee('Panel Seller')->assertSee('Seller Street')
        ->assertSee('PRINT-PANEL')->assertSee('Printed panel')->assertSee('Rs 59.00')->assertSee('One damaged panel')
        ->assertSee('Invoice footer on credit note')->assertSee('window.print()', false)->assertDontSee('Edited later')->assertDontSee('Edited buyer')->assertDontSee('CHANGED-VAT');
    if ($taxInvoice) {
        $response->assertSee('VAT included at 18%: Rs 9.00')->assertSee('SELLER-VAT');
    } else {
        $response->assertDontSee('VAT included')->assertDontSee('Seller VAT number');
    }
    $this->get($issued->url)->assertOk()->assertSee('Credit notes')->assertSee($credit->number)->assertSee('Rs 59.00')->assertSee('Balance due');
    expect(app(BuildCreditNoteDocument::class)->handle($credit)->buyer)->toEqual($issued->invoice->customer_snapshot);
})->with([false, true]);

it('requires sign in and view authorization to print a credit note', function () {
    app(IssueInvoice::class)->handle($this->order, null, $this->by);
    $return = app(CreateReturn::class)->handle($this->order, [new ReturnLineInput($this->line->id, 1, 5900, false)], 'Return', null, null, $this->by);
    $url = route('filament.admin.credit-notes.print', ['creditNote' => $return->creditNote]);
    $this->get($url)->assertRedirect(route('filament.admin.auth.login'));
    $this->actingAs(User::factory()->stock()->withAppAuthentication()->create())->get($url)->assertForbidden();
    $this->actingAs(User::factory()->admin()->withAppAuthentication()->create())->get($url)->assertOk();
});
