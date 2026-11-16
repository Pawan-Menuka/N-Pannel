<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class DocumentSettings extends Settings
{
    public int $invoice_due_days;

    public string $quote_footer;

    public string $invoice_footer;

    public bool $show_bank_details_on_invoice;

    public static function group(): string
    {
        return 'documents';
    }
}
