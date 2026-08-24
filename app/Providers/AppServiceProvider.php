<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('payrolls')) {
                \Illuminate\Support\Facades\Schema::create('payrolls', function (\Illuminate\Database\Schema\Blueprint $table) {
                    $table->id();
                    $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
                    $table->foreignId('employee_id')->constrained('users')->onDelete('cascade');
                    $table->string('pay_period_month');
                    $table->integer('pay_period_year');
                    $table->date('pay_date')->nullable();
                    $table->string('payment_mode')->default('bank_transfer');
                    $table->string('status')->default('generated');
                    $table->json('earnings')->nullable();
                    $table->json('deductions')->nullable();
                    $table->decimal('total_earnings', 14, 2)->default(0.00);
                    $table->decimal('total_deductions', 14, 2)->default(0.00);
                    $table->decimal('net_salary', 14, 2)->default(0.00);
                    $table->string('net_salary_words')->nullable();
                    $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
                    $table->timestamp('paid_at')->nullable();
                    $table->text('notes')->nullable();
                    $table->timestamps();
                });
            }
        } catch (\Throwable $e) {
            // Ignore if DB connection is not initialized during static analysis
        }
    }
}
