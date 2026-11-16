<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE UNIQUE INDEX variants_sku_upper_unique ON variants (upper(sku))');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX variants_sku_upper_unique');
    }
};
