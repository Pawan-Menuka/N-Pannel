<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('phone_e164', 16)->nullable()->unique();
            $table->string('name');
            $table->boolean('name_edited_by_staff')->default(false);
            $table->string('type')->default('homeowner');
            $table->string('price_tier')->default('retail');
            $table->string('company_name')->nullable();
            $table->string('vat_number')->nullable();
            $table->string('email')->nullable();
            $table->string('preferred_locale', 2)->default('en');
            $table->foreignId('district_id')->nullable()->constrained('districts')->nullOnDelete();
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('city')->nullable();
            $table->jsonb('tags')->default('[]');
            $table->text('notes')->nullable();
            $table->foreignId('assigned_to_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('marketing_opt_in')->default(false);
            $table->timestamp('marketing_opt_in_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('anonymised_at')->nullable();
            $table->foreignId('merged_into_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->boolean('is_walk_in')->default(false);
            $table->timestamps();
            $table->index('name');
            $table->index('last_activity_at');
        });

        DB::statement('CREATE UNIQUE INDEX customers_one_walk_in ON customers (is_walk_in) WHERE is_walk_in');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS customers_one_walk_in');

        Schema::dropIfExists('customers');
    }
};
