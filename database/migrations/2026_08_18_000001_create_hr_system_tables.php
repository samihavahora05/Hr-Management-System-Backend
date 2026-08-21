<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Organizations
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        // 2. Roles
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique(); // admin, hr, manager, employee
            $table->string('display_name');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // Update / Enhance Users Table (Employees)
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->onDelete('cascade');
            $table->foreignId('role_id')->nullable()->constrained('roles')->onDelete('set null');
            $table->string('employee_code')->nullable()->unique();
            $table->string('department')->nullable();
            $table->string('designation')->nullable();
            $table->date('joining_date')->nullable();
            $table->enum('status', ['active', 'inactive', 'on_leave'])->default('active');
            $table->string('phone')->nullable();
            $table->string('avatar')->nullable();
            $table->decimal('base_salary', 12, 2)->default(0.00);
            $table->foreignId('manager_id')->nullable()->constrained('users')->onDelete('set null');
        });

        // 3. Employee Documents
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('title');
            $table->string('type')->default('contract'); // id_proof, contract, certificate, tax
            $table->string('file_url');
            $table->timestamps();
        });

        // 4. Audit Logs
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->foreignId('actor_id')->nullable()->constrained('users')->onDelete('set null');
            $table->string('action');
            $table->string('target_type')->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
        });

        // 5. Attendances
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->date('date');
            $table->time('check_in')->nullable();
            $table->time('check_out')->nullable();
            $table->enum('status', ['present', 'absent', 'late', 'half_day', 'on_leave'])->default('present');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'date']);
        });

        // 6. Leave Types
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->string('name'); // Sick Leave, Casual Leave, Paid Time Off, Maternity Leave
            $table->integer('annual_quota')->default(12);
            $table->boolean('is_paid')->default(true);
            $table->timestamps();
        });

        // 7. Leave Balances
        Schema::create('leave_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('leave_type_id')->constrained('leave_types')->onDelete('cascade');
            $table->integer('allocated')->default(12);
            $table->integer('used')->default(0);
            $table->integer('remaining')->default(12);
            $table->timestamps();

            $table->unique(['user_id', 'leave_type_id']);
        });

        // 8. Leave Requests
        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('leave_type_id')->constrained('leave_types')->onDelete('cascade');
            $table->date('start_date');
            $table->date('end_date');
            $table->integer('days_count')->default(1);
            $table->text('reason')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->foreignId('approver_id')->nullable()->constrained('users')->onDelete('set null');
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
        });

        // 9. Salary Structures
        Schema::create('salary_structures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->foreignId('user_id')->unique()->constrained('users')->onDelete('cascade');
            $table->decimal('base_salary', 12, 2)->default(0.00);
            $table->decimal('housing_allowance', 12, 2)->default(0.00);
            $table->decimal('transport_allowance', 12, 2)->default(0.00);
            $table->decimal('tax_deduction', 12, 2)->default(0.00);
            $table->decimal('other_deductions', 12, 2)->default(0.00);
            $table->decimal('net_salary', 12, 2)->default(0.00);
            $table->timestamps();
        });

        // 10. Payroll Records
        Schema::create('payroll_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('month_year'); // e.g. "2026-08"
            $table->decimal('gross_salary', 12, 2);
            $table->decimal('total_deductions', 12, 2);
            $table->decimal('net_salary', 12, 2);
            $table->enum('status', ['draft', 'processed', 'paid'])->default('draft');
            $table->timestamp('paid_at')->nullable();
            $table->string('payslip_url')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'month_year']);
        });

        // 11. Announcements
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->foreignId('author_id')->constrained('users')->onDelete('cascade');
            $table->string('title');
            $table->text('content');
            $table->string('target_role')->default('all'); // all, manager, employee
            $table->boolean('is_pinned')->default(false);
            $table->timestamps();
        });

        // 12. Onboarding Checklists
        Schema::create('onboarding_checklists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('title');
            $table->enum('type', ['onboarding', 'offboarding'])->default('onboarding');
            $table->enum('status', ['pending', 'in_progress', 'completed'])->default('pending');
            $table->json('items')->nullable(); // array of { id, text, completed, completed_at }
            $table->timestamps();
        });

        // 13. Employee Risk Scores (Phase 7 AI Attrition & Anomaly Insights)
        Schema::create('employee_risk_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->integer('risk_score')->default(0); // 0 to 100
            $table->enum('risk_level', ['Low', 'Medium', 'High'])->default('Low');
            $table->json('contributing_factors')->nullable(); // array of strings
            $table->timestamp('calculated_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_risk_scores');
        Schema::dropIfExists('onboarding_checklists');
        Schema::dropIfExists('announcements');
        Schema::dropIfExists('payroll_records');
        Schema::dropIfExists('salary_structures');
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('leave_balances');
        Schema::dropIfExists('leave_types');
        Schema::dropIfExists('attendances');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('documents');

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropForeign(['role_id']);
            $table->dropForeign(['manager_id']);
            $table->dropColumn([
                'organization_id', 'role_id', 'employee_code', 'department',
                'designation', 'joining_date', 'status', 'phone', 'avatar',
                'base_salary', 'manager_id'
            ]);
        });

        Schema::dropIfExists('roles');
        Schema::dropIfExists('organizations');
    }
};
