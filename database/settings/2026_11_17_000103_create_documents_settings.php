<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('documents.invoice_due_days', 0);
        $this->migrator->add('documents.quote_footer', '');
        $this->migrator->add('documents.invoice_footer', '');
        $this->migrator->add('documents.show_bank_details_on_invoice', true);
    }

    public function down(): void
    {
        $this->migrator->delete('documents.show_bank_details_on_invoice');
        $this->migrator->delete('documents.invoice_footer');
        $this->migrator->delete('documents.quote_footer');
        $this->migrator->delete('documents.invoice_due_days');
    }
};
