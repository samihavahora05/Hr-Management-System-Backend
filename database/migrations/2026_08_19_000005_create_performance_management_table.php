<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Performance Cycles
        Schema::create('performance_cycles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->string('title'); // e.g., "Q3 2026 Annual Review Cycle"
            $table->date('start_date');
            $table->date('end_date');
            $table->enum('status', ['draft', 'active', 'in_review', 'completed'])->default('draft');
            $table->timestamps();
        });

        // 2. Goals / KPIs
        Schema::create('goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->foreignId('cycle_id')->nullable()->constrained('performance_cycles')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('title');
            $table->text('description')->nullable();
            $table->decimal('target_value', 8, 2)->default(100.00);
            $table->decimal('current_value', 8, 2)->default(0.00);
            $table->integer('weightage')->default(20); // Percentage weightage
            $table->enum('status', ['not_started', 'in_progress', 'achieved', 'partially_achieved', 'cancelled'])->default('in_progress');
            $table->text('manager_comment')->nullable();
            $table->timestamps();
        });

        // 3. Performance Reviews
        Schema::create('performance_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->foreignId('cycle_id')->constrained('performance_cycles')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('reviewer_id')->constrained('users')->onDelete('cascade');
            $table->integer('self_rating')->nullable(); // 1 to 5
            $table->integer('manager_rating')->nullable(); // 1 to 5
            $table->decimal('final_rating', 3, 2)->nullable(); // 1.00 to 5.00
            $table->text('self_feedback')->nullable();
            $table->text('manager_feedback')->nullable();
            $table->enum('status', ['pending_self_review', 'pending_manager_review', 'completed'])->default('pending_self_review');
            $table->timestamps();

            $table->unique(['cycle_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_reviews');
        Schema::dropIfExists('goals');
        Schema::dropIfExists('performance_cycles');
    }
};
