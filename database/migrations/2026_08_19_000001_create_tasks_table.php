<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->foreignId('assigner_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('assigned_to')->constrained('users')->onDelete('cascade');
            $table->string('assigned_by_role')->default('admin'); // admin, hr, manager, team_leader, employee
            $table->string('assigned_to_role')->default('employee'); // admin, hr, manager, team_leader, employee
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('category')->default('general');
            $table->enum('priority', ['low', 'medium', 'high', 'urgent'])->default('medium');
            $table->enum('status', ['todo', 'in_progress', 'completed', 'overdue', 'cancelled'])->default('todo');
            $table->integer('progress_percentage')->default(0);
            $table->date('start_date')->nullable();
            $table->date('due_date')->nullable();
            $table->json('subtasks')->nullable();
            $table->text('notes')->nullable();
            $table->text('completion_notes')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
