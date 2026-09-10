<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Upgrade tasks table with verification, marks and lifecycle tracking columns
        Schema::table('tasks', function (Blueprint $table) {
            if (!Schema::hasColumn('tasks', 'maximum_marks')) {
                $table->integer('maximum_marks')->default(100);
            }
            if (!Schema::hasColumn('tasks', 'marks_awarded')) {
                $table->integer('marks_awarded')->nullable();
            }
            if (!Schema::hasColumn('tasks', 'started_at')) {
                $table->timestamp('started_at')->nullable();
            }
            if (!Schema::hasColumn('tasks', 'started_by')) {
                $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('tasks', 'submitted_at')) {
                $table->timestamp('submitted_at')->nullable();
            }
            if (!Schema::hasColumn('tasks', 'reviewed_at')) {
                $table->timestamp('reviewed_at')->nullable();
            }
            if (!Schema::hasColumn('tasks', 'reviewed_by')) {
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('tasks', 'admin_feedback')) {
                $table->text('admin_feedback')->nullable();
            }
        });

        // 2. Create task_submissions table (supporting multi-submission iteration history)
        if (!Schema::hasTable('task_submissions')) {
            Schema::create('task_submissions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('task_id')->constrained('tasks')->onDelete('cascade');
                $table->foreignId('employee_id')->constrained('users')->onDelete('cascade');
                $table->integer('submission_number')->default(1);
                $table->text('completion_note');
                $table->text('what_was_completed')->nullable();
                $table->text('employee_comment')->nullable();
                $table->string('status')->default('submitted'); // submitted, approved, needs_revision
                $table->timestamp('submitted_at')->useCurrent();
                $table->timestamp('reviewed_at')->nullable();
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('admin_feedback')->nullable();
                $table->integer('marks_awarded')->nullable();
                $table->integer('maximum_marks')->default(100);
                $table->timestamps();
            });
        }

        // 3. Create task_submission_files table (proof & evidence attachments)
        if (!Schema::hasTable('task_submission_files')) {
            Schema::create('task_submission_files', function (Blueprint $table) {
                $table->id();
                $table->foreignId('submission_id')->constrained('task_submissions')->onDelete('cascade');
                $table->foreignId('task_id')->constrained('tasks')->onDelete('cascade');
                $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
                $table->string('original_name');
                $table->string('file_path');
                $table->string('file_type')->nullable(); // mime type or extension
                $table->unsignedBigInteger('file_size')->default(0); // in bytes
                $table->timestamps();
            });
        }

        // 4. Create task_activities table (Complete immutable audit trail)
        if (!Schema::hasTable('task_activities')) {
            Schema::create('task_activities', function (Blueprint $table) {
                $table->id();
                $table->foreignId('task_id')->constrained('tasks')->onDelete('cascade');
                $table->foreignId('submission_id')->nullable()->constrained('task_submissions')->nullOnDelete();
                $table->string('event_type'); // task_created, task_assigned, task_started, task_submitted, proof_uploaded, revision_requested, task_approved, marks_awarded, marks_updated, task_reopened, task_cancelled, task_updated
                $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('performed_by_role')->nullable();
                $table->string('previous_status')->nullable();
                $table->string('new_status')->nullable();
                $table->text('description');
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('task_activities');
        Schema::dropIfExists('task_submission_files');
        Schema::dropIfExists('task_submissions');

        Schema::table('tasks', function (Blueprint $table) {
            $columns = [
                'maximum_marks',
                'marks_awarded',
                'started_at',
                'started_by',
                'submitted_at',
                'reviewed_at',
                'reviewed_by',
                'admin_feedback',
            ];
            foreach ($columns as $column) {
                if (Schema::hasColumn('tasks', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
