<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('quotes.validity_days', 7);
        $this->migrator->add('quotes.default_advance_pct', 50);
    }

    public function down(): void
    {
        $this->migrator->delete('quotes.default_advance_pct');
        $this->migrator->delete('quotes.validity_days');
    }
};
