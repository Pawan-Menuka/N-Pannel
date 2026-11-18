<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->index('confirmed_at');
        });
        Schema::table('payments', function (Blueprint $table): void {
            $table->index(['status', 'confirmed_at']);
        });
        Schema::table('sales_returns', function (Blueprint $table): void {
            $table->index('created_at');
        });
        Schema::table('goods_receipts', function (Blueprint $table): void {
            $table->index(['status', 'posted_at']);
        });
    }

    public function down(): void
    {
        Schema::table('goods_receipts', function (Blueprint $table): void {
            $table->dropIndex(['status', 'posted_at']);
        });
        Schema::table('sales_returns', function (Blueprint $table): void {
            $table->dropIndex(['created_at']);
        });
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropIndex(['status', 'confirmed_at']);
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex(['confirmed_at']);
        });
    }
};
