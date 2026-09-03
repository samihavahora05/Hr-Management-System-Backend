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
        if (!Schema::hasTable('monthly_attendance_reports')) {
            Schema::create('monthly_attendance_reports', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
                $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
                $table->string('title');
                $table->string('month'); // e.g. "2026-09"
                $table->integer('year');
                $table->string('month_name'); // e.g. "September 2026"
                $table->string('department')->default('all');
                $table->integer('total_employees')->default(0);
                $table->integer('total_working_days')->default(0);
                $table->decimal('avg_attendance_percentage', 5, 2)->default(0.00);
                $table->decimal('avg_performance_rate', 5, 2)->default(0.00);
                $table->json('summary')->nullable(); // aggregate counts
                $table->json('records')->nullable(); // detailed employee rows + day-by-day logs
                $table->string('status')->default('stored'); // stored, finalized, archived
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('monthly_attendance_reports');
    }
};
