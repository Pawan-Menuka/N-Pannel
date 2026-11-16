<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('kind');
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('next_number')->default(1);
            $table->timestamps();
            $table->unique(['kind', 'year']);
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->string('token_hash', 64)->nullable()->unique();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('status')->default('issued');
            $table->string('locale', 2)->default('en');
            $table->jsonb('customer_snapshot');
            $table->jsonb('lines');
            $table->unsignedBigInteger('subtotal_cents');
            $table->unsignedBigInteger('delivery_cents')->default(0);
            $table->unsignedBigInteger('discount_cents')->default(0);
            $table->unsignedBigInteger('tax_cents')->default(0);
            $table->unsignedBigInteger('total_cents');
            $table->unsignedSmallInteger('vat_rate_bp')->nullable();
            $table->string('seller_vat_number')->nullable();
            $table->date('issued_on');
            $table->date('due_on')->nullable();
            $table->foreignId('issued_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason')->nullable();
            $table->timestamps();
            $table->index(['order_id', 'status']);
            $table->index(['status', 'due_on']);
        });

        DB::statement("CREATE UNIQUE INDEX invoices_one_issued_per_order ON invoices (order_id) WHERE status = 'issued'");

        Schema::create('sales_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->string('reason');
            $table->text('note')->nullable();
            $table->unsignedBigInteger('total_cents')->default(0);
            $table->unsignedBigInteger('refund_cents')->default(0);
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('sales_return_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_return_id')->constrained('sales_returns')->cascadeOnDelete();
            $table->foreignId('order_line_id')->constrained('order_lines')->restrictOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('variants')->nullOnDelete();
            $table->unsignedInteger('qty');
            $table->boolean('restock')->default(true);
            $table->bigInteger('unit_price_cents');
            $table->bigInteger('line_total_cents');
            $table->timestamps();
        });

        Schema::create('credit_notes', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->foreignId('sales_return_id')->nullable()->constrained('sales_returns')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('status')->default('issued');
            $table->jsonb('customer_snapshot');
            $table->jsonb('lines');
            $table->unsignedBigInteger('total_cents');
            $table->unsignedBigInteger('tax_cents')->default(0);
            $table->unsignedSmallInteger('vat_rate_bp')->nullable();
            $table->string('seller_vat_number')->nullable();
            $table->date('issued_on');
            $table->foreignId('issued_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason')->nullable();
            $table->timestamps();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('sales_return_id')->nullable()->constrained('sales_returns')->nullOnDelete();
        });
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS invoices_one_issued_per_order');

        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sales_return_id');
        });

        Schema::dropIfExists('credit_notes');
        Schema::dropIfExists('sales_return_lines');
        Schema::dropIfExists('sales_returns');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('document_sequences');
    }
};
