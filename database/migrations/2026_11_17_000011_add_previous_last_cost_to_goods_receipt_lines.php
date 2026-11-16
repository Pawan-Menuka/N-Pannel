<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goods_receipt_lines', function (Blueprint $table) {
            $table->unsignedBigInteger('previous_last_cost_cents')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('goods_receipt_lines', function (Blueprint $table) {
            $table->dropColumn('previous_last_cost_cents');
        });
    }
};
