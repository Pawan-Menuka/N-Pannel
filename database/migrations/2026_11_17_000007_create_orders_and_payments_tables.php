<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->string('token_hash', 64)->nullable()->unique();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('source')->default('quote');
            $table->string('locale', 2)->default('en');
            $table->string('status');
            $table->string('payment_state')->default('unpaid');
            $table->string('fulfilment')->nullable();
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->string('price_tier')->default('retail');
            $table->foreignId('district_id')->nullable()->constrained('districts')->nullOnDelete();
            $table->string('customer_name');
            $table->string('phone_e164', 16)->nullable();
            $table->string('email')->nullable();
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('city')->nullable();
            $table->text('delivery_notes')->nullable();
            $table->unsignedBigInteger('subtotal_cents')->default(0);
            $table->unsignedBigInteger('delivery_cents')->default(0);
            $table->unsignedBigInteger('discount_cents')->default(0);
            $table->unsignedBigInteger('total_cents')->default(0);
            $table->unsignedBigInteger('tax_cents')->default(0);
            $table->unsignedBigInteger('paid_cents')->default(0);
            $table->unsignedBigInteger('refunded_cents')->default(0);
            $table->unsignedBigInteger('required_advance_cents')->nullable();
            $table->text('exclusions')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->timestamp('hold_expires_at')->nullable();
            $table->unsignedSmallInteger('quote_revision')->default(0);
            $table->timestamp('accepted_at')->nullable();
            $table->string('accepted_via')->nullable();
            $table->boolean('needs_attention')->default(false);
            $table->string('attention_note')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('placed_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
            $table->index('hold_expires_at');
            $table->index('needs_attention');
            $table->index(['customer_id', 'created_at']);
        });

        Schema::create('order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('kind')->default('product');
            $table->foreignId('variant_id')->nullable()->constrained('variants')->nullOnDelete();
            $table->string('sku')->nullable();
            $table->string('description');
            $table->string('sale_unit')->nullable();
            $table->unsignedInteger('qty');
            $table->bigInteger('unit_price_cents');
            $table->bigInteger('line_total_cents');
            $table->unsignedBigInteger('unit_cost_cents')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->string('kind')->default('payment');
            $table->string('method');
            $table->string('status')->default('pending');
            $table->unsignedBigInteger('amount_cents');
            $table->string('reference')->nullable();
            $table->string('receipt_number')->nullable()->unique();
            $table->string('note')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('confirmed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->index(['order_id', 'status']);
        });

        Schema::create('order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('type');
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->string('note')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['order_id', 'created_at']);
        });

        Schema::create('quote_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->unsignedSmallInteger('revision');
            $table->jsonb('lines');
            $table->unsignedBigInteger('total_cents');
            $table->unsignedBigInteger('required_advance_cents')->nullable();
            $table->text('exclusions')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['order_id', 'revision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_snapshots');
        Schema::dropIfExists('order_events');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('order_lines');
        Schema::dropIfExists('orders');
    }
};
