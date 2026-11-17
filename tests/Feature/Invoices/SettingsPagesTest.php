<?php

use App\Filament\Pages\ManageBankDetails;
use App\Filament\Pages\ManageBusinessDetails;
use App\Filament\Pages\ManageDocuments;
use App\Filament\Pages\ManageQuotes;
use App\Filament\Pages\ManageTax;
use App\Models\User;
use App\Settings\BankSettings;
use App\Settings\BusinessSettings;
use App\Settings\DocumentSettings;
use App\Settings\QuoteSettings;
use App\Settings\TaxSettings;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(User::factory()->admin()->withAppAuthentication()->create());
});

it('limits every settings page to admins', function (string $page, string $role) {
    $this->actingAs(User::factory()->withAppAuthentication()->create(['role' => $role]));
    expect($page::canAccess())->toBe($role === 'admin');
    $response = $this->get($page::getUrl());
    if ($role === 'admin') {
        $response->assertOk();
        Livewire::test($page)->assertOk();
    } else {
        $response->assertForbidden();
        Livewire::test($page)->assertForbidden();
    }
})->with([ManageBusinessDetails::class, ManageTax::class, ManageDocuments::class, ManageBankDetails::class, ManageQuotes::class])->with(['admin', 'sales', 'stock']);

it('saves business details and validates WhatsApp digits', function () {
    Livewire::test(ManageBusinessDetails::class)->fillForm([
        'name' => 'New Business', 'phone' => '+94112223333', 'whatsapp_digits' => '94771234567',
        'email' => 'office@example.test', 'address' => 'Business Street', 'response_hours' => '8am to 5pm',
    ])->call('save')->assertHasNoFormErrors();
    $settings = app(BusinessSettings::class);
    expect($settings->name)->toBe('New Business');
    expect($settings->phone)->toBe('+94112223333');
    expect($settings->whatsapp_digits)->toBe('94771234567');
    expect($settings->email)->toBe('office@example.test');
    expect($settings->address)->toBe('Business Street');
    expect($settings->response_hours)->toBe('8am to 5pm');
    Livewire::test(ManageBusinessDetails::class)->fillForm(['whatsapp_digits' => '+94771234567'])->call('save')->assertHasFormErrors(['whatsapp_digits']);
    Livewire::test(ManageBusinessDetails::class)->fillForm(['name' => ''])->call('save')->assertHasFormErrors(['name']);
});

it('saves and reloads VAT percentages as integer basis points', function (string $percent, int $bp) {
    Livewire::test(ManageTax::class)->fillForm(['vat_number' => '  VAT-123  ', 'vat_percent' => $percent])->call('save')->assertHasNoFormErrors();
    expect(app(TaxSettings::class)->vat_rate_bp)->toBe($bp);
    expect(app(TaxSettings::class)->vat_number)->toBe('VAT-123');
    expect(app(TaxSettings::class)->prices_include_vat)->toBeTrue();
    Livewire::test(ManageTax::class)->assertFormSet(['vat_percent' => $percent, 'vat_number' => 'VAT-123'])->assertFormFieldDoesNotExist('prices_include_vat');
})->with([['18', 1800], ['12.5', 1250]]);

it('rejects fractional VAT precision and allows clearing registration', function () {
    Livewire::test(ManageTax::class)->fillForm(['vat_number' => 'VAT', 'vat_percent' => '18.555'])->call('save')->assertHasFormErrors(['vat_percent']);
    Livewire::test(ManageTax::class)->fillForm(['vat_number' => '', 'vat_percent' => ''])->call('save')->assertHasNoFormErrors();
    expect(app(TaxSettings::class)->vat_number)->toBeNull();
    expect(app(TaxSettings::class)->vat_rate_bp)->toBeNull();
});

it('refuses incomplete tax registration on the settings page', function (?string $number, ?string $percent) {
    Livewire::test(ManageTax::class)->fillForm(['vat_number' => $number, 'vat_percent' => $percent])
        ->call('save')->assertHasFormErrors(['vat_number', 'vat_percent'])
        ->assertSee('Tax settings are incomplete: set both the VAT number and rate, or neither.');
    expect(app(TaxSettings::class)->vat_number)->toBeNull();
    expect(app(TaxSettings::class)->vat_rate_bp)->toBeNull();
})->with([['VAT', null], [null, '18'], ['  ', '18'], ['VAT', '0'], [null, '0']]);

it('saves document settings and rejects due days outside the bounds', function () {
    Livewire::test(ManageDocuments::class)->fillForm([
        'invoice_due_days' => 14, 'quote_footer' => 'Quote terms', 'invoice_footer' => 'Invoice terms', 'show_bank_details_on_invoice' => true,
    ])->call('save')->assertHasNoFormErrors();
    $settings = app(DocumentSettings::class);
    expect($settings->invoice_due_days)->toBe(14);
    expect($settings->quote_footer)->toBe('Quote terms');
    expect($settings->invoice_footer)->toBe('Invoice terms');
    expect($settings->show_bank_details_on_invoice)->toBeTrue();
    foreach ([-1, 366] as $days) {
        Livewire::test(ManageDocuments::class)->fillForm(['invoice_due_days' => $days])->call('save')->assertHasFormErrors(['invoice_due_days']);
    }
});

it('saves bank details including leading zeroes in account numbers', function () {
    Livewire::test(ManageBankDetails::class)->fillForm([
        'account_name' => 'Account Holder', 'bank_name' => 'Bank', 'branch' => 'Branch', 'account_number' => '001234567',
    ])->call('save')->assertHasNoFormErrors();
    $settings = app(BankSettings::class);
    expect($settings->account_name)->toBe('Account Holder');
    expect($settings->bank_name)->toBe('Bank');
    expect($settings->branch)->toBe('Branch');
    expect($settings->account_number)->toBe('001234567');
});

it('saves quote settings and validates both bounds', function () {
    Livewire::test(ManageQuotes::class)->fillForm(['validity_days' => 30, 'default_advance_pct' => 25])->call('save')->assertHasNoFormErrors();
    expect(app(QuoteSettings::class)->validity_days)->toBe(30);
    expect(app(QuoteSettings::class)->default_advance_pct)->toBe(25);
    foreach ([['validity_days', 0], ['validity_days', 366], ['default_advance_pct', -1], ['default_advance_pct', 101]] as [$field, $value]) {
        Livewire::test(ManageQuotes::class)->fillForm([$field => $value])->call('save')->assertHasFormErrors([$field]);
    }
});

it('normalises empty optional string settings and preserves fields absent from the form', function () {
    Livewire::test(ManageBusinessDetails::class)->fillForm(['name' => 'Business', 'phone' => '', 'email' => '', 'address' => '', 'whatsapp_digits' => '', 'response_hours' => ''])->call('save')->assertHasNoFormErrors();
    expect(app(BusinessSettings::class)->phone)->toBe('');
    Livewire::test(ManageBankDetails::class)->fillForm(['account_name' => '', 'account_number' => '', 'bank_name' => '', 'branch' => ''])->call('save')->assertHasNoFormErrors();
    expect(app(BankSettings::class)->account_number)->toBe('');
    Livewire::test(ManageDocuments::class)->fillForm(['quote_footer' => '', 'invoice_footer' => ''])->call('save')->assertHasNoFormErrors();
    expect(app(DocumentSettings::class)->invoice_footer)->toBe('');
});

it('revokes saving when the mounted settings user loses admin access', function (string $page) {
    $component = Livewire::test($page);
    auth()->user()->forceFill(['role' => 'sales'])->save();
    $component->call('save')->assertForbidden();
})->with([ManageBusinessDetails::class, ManageTax::class, ManageDocuments::class, ManageBankDetails::class, ManageQuotes::class]);
