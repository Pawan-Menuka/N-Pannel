<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('business.name', 'N-Pannel');
        $this->migrator->add('business.phone', '');
        $this->migrator->add('business.whatsapp_digits', '');
        $this->migrator->add('business.email', '');
        $this->migrator->add('business.address', '');
        $this->migrator->add('business.response_hours', '');
    }

    public function down(): void
    {
        $this->migrator->delete('business.response_hours');
        $this->migrator->delete('business.address');
        $this->migrator->delete('business.email');
        $this->migrator->delete('business.whatsapp_digits');
        $this->migrator->delete('business.phone');
        $this->migrator->delete('business.name');
    }
};
