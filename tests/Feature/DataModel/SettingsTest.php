<?php

use App\Settings\BankSettings;
use App\Settings\BusinessSettings;
use App\Settings\DocumentSettings;
use App\Settings\QuoteSettings;
use App\Settings\TaxSettings;

it('loads the initial business and document settings', function () {
    expect(app(BusinessSettings::class)->toArray())->toBe([
        'name' => 'N-Pannel',
        'phone' => '',
        'whatsapp_digits' => '',
        'email' => '',
        'address' => '',
        'response_hours' => '',
    ]);
    expect(app(TaxSettings::class)->toArray())->toBe([
        'prices_include_vat' => true,
        'vat_rate_bp' => null,
        'vat_number' => null,
    ]);
    expect(app(DocumentSettings::class)->toArray())->toBe([
        'invoice_due_days' => 0,
        'quote_footer' => '',
        'invoice_footer' => '',
        'show_bank_details_on_invoice' => true,
    ]);
    expect(app(QuoteSettings::class)->toArray())->toBe([
        'validity_days' => 7,
        'default_advance_pct' => 50,
    ]);
    expect(app(BankSettings::class)->toArray())->toBe([
        'account_name' => '',
        'bank_name' => '',
        'branch' => '',
        'account_number' => '',
    ]);
});
