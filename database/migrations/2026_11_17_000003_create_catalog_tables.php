<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->jsonb('name');
            $table->jsonb('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->foreignId('category_id')->constrained('categories')->restrictOnDelete();
            $table->string('brand')->nullable();
            $table->jsonb('title');
            $table->jsonb('summary')->nullable();
            $table->jsonb('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->index(['category_id', 'is_active', 'sort_order']);
        });

        Schema::create('variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('sku')->unique();
            $table->string('label')->nullable();
            $table->string('colour')->nullable();
            $table->unsignedInteger('length_mm')->nullable();
            $table->unsignedInteger('width_mm')->nullable();
            $table->unsignedInteger('thickness_micron')->nullable();
            $table->unsignedInteger('weight_grams')->nullable();
            $table->string('sale_unit');
            $table->unsignedInteger('pieces_per_unit')->default(1);
            $table->unsignedBigInteger('price_cents');
            $table->unsignedBigInteger('trade_price_cents')->nullable();
            $table->unsignedBigInteger('last_cost_cents')->nullable();
            $table->boolean('track_stock')->default(true);
            $table->unsignedInteger('reorder_level')->nullable();
            $table->string('stock_status')->default('in_stock');
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->index(['product_id', 'is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('variants');
        Schema::dropIfExists('products');
        Schema::dropIfExists('categories');
    }
};
