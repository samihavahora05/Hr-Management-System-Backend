<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('payrolls')) {
            Schema::create('payrolls', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
                $table->foreignId('employee_id')->constrained('users')->onDelete('cascade');
                $table->string('pay_period_month'); // e.g. "May"
                $table->integer('pay_period_year'); // e.g. 2025
                $table->date('pay_date');
                $table->string('payment_mode')->default('bank_transfer'); // bank_transfer, cash, cheque
                $table->enum('status', ['draft', 'generated', 'paid'])->default('generated');
                $table->json('earnings')->nullable(); // [{"particulars": "Basic Salary", "amount": 50000}, ...]
                $table->json('deductions')->nullable(); // [{"particulars": "Provident Fund (PF)", "amount": 1800}, ...]
                $table->decimal('total_earnings', 14, 2)->default(0.00);
                $table->decimal('total_deductions', 14, 2)->default(0.00);
                $table->decimal('net_salary', 14, 2)->default(0.00);
                $table->string('net_salary_words')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
                $table->timestamp('paid_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['organization_id', 'pay_period_year', 'pay_period_month']);
                $table->unique(['organization_id', 'employee_id', 'pay_period_month', 'pay_period_year'], 'org_emp_month_year_unique');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payrolls');
    }
};
