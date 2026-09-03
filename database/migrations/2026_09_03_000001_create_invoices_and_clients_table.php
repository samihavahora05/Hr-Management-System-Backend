<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('clients')) {
            Schema::create('clients', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->nullable()->constrained()->onDelete('cascade');
                $table->string('name');
                $table->string('company_name')->nullable();
                $table->string('email')->nullable();
                $table->string('phone')->nullable();
                $table->string('tax_number')->nullable();
                $table->text('address')->nullable();
                $table->string('city')->nullable();
                $table->string('state')->nullable();
                $table->string('postal_code')->nullable();
                $table->string('country')->default('India');
                $table->string('currency')->default('INR');
                $table->text('notes')->nullable();
                $table->string('status')->default('active');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('invoices')) {
            Schema::create('invoices', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->nullable()->constrained()->onDelete('cascade');
                $table->string('invoice_number')->index();
                $table->string('reference_number')->nullable();
                $table->foreignId('client_id')->nullable()->constrained('clients')->onDelete('set null');
                $table->string('client_name');
                $table->string('client_company_name')->nullable();
                $table->string('client_email')->nullable();
                $table->string('client_phone')->nullable();
                $table->text('client_address')->nullable();
                $table->string('client_city')->nullable();
                $table->string('client_state')->nullable();
                $table->string('client_postal_code')->nullable();
                $table->string('client_country')->default('India');
                $table->string('client_tax_number')->nullable();
                $table->date('invoice_date');
                $table->date('due_date')->nullable();
                $table->string('currency')->default('INR');
                $table->decimal('subtotal', 14, 2)->default(0);
                $table->string('discount_type')->default('fixed'); // 'fixed' or 'percentage'
                $table->decimal('discount_value', 14, 2)->default(0);
                $table->decimal('discount_amount', 14, 2)->default(0);
                $table->decimal('tax_percentage', 5, 2)->default(0);
                $table->decimal('tax_amount', 14, 2)->default(0);
                $table->decimal('total_amount', 14, 2)->default(0);
                $table->decimal('paid_amount', 14, 2)->default(0);
                $table->decimal('balance_due', 14, 2)->default(0);
                $table->string('payment_status')->default('unpaid'); // 'unpaid', 'partially_paid', 'paid', 'overdue'
                $table->string('status')->default('sent'); // 'draft', 'sent', 'paid', 'cancelled'
                $table->string('payment_method')->nullable();
                $table->string('payment_reference')->nullable();
                $table->date('payment_date')->nullable();
                $table->text('notes')->nullable();
                $table->text('terms')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('invoice_items')) {
            Schema::create('invoice_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('invoice_id')->constrained('invoices')->onDelete('cascade');
                $table->string('item_name');
                $table->text('description')->nullable();
                $table->decimal('quantity', 10, 2)->default(1);
                $table->decimal('rate', 14, 2)->default(0);
                $table->decimal('discount', 14, 2)->default(0);
                $table->decimal('tax_percentage', 5, 2)->default(0);
                $table->decimal('total', 14, 2)->default(0);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('clients');
    }
};
