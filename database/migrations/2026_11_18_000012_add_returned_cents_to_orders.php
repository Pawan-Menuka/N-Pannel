<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('returned_cents')->default(0);
        });
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_returned_cents_check CHECK (returned_cents <= total_cents)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_returned_cents_check');
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('returned_cents');
        });
    }
};
