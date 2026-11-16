<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('bank.account_name', '');
        $this->migrator->add('bank.bank_name', '');
        $this->migrator->add('bank.branch', '');
        $this->migrator->add('bank.account_number', '');
    }

    public function down(): void
    {
        $this->migrator->delete('bank.account_number');
        $this->migrator->delete('bank.branch');
        $this->migrator->delete('bank.bank_name');
        $this->migrator->delete('bank.account_name');
    }
};
