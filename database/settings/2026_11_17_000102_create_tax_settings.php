<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('tax.prices_include_vat', true);
        $this->migrator->add('tax.vat_rate_bp', null);
        $this->migrator->add('tax.vat_number', null);
    }

    public function down(): void
    {
        $this->migrator->delete('tax.vat_number');
        $this->migrator->delete('tax.vat_rate_bp');
        $this->migrator->delete('tax.prices_include_vat');
    }
};
