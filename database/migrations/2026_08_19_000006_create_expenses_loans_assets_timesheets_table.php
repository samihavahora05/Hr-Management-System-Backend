<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Expense Claims
        Schema::create('expense_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('category'); // Travel, Food, Internet, Equipment, Office Supplies
            $table->decimal('amount', 10, 2);
            $table->date('claim_date');
            $table->text('description');
            $table->string('receipt_url')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected', 'reimbursed'])->default('pending');
            $table->foreignId('approver_id')->nullable()->constrained('users')->onDelete('set null');
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
        });

        // 2. Loan Requests
        Schema::create('loan_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->decimal('amount', 10, 2);
            $table->decimal('monthly_installment', 10, 2);
            $table->integer('tenure_months');
            $table->text('reason');
            $table->enum('status', ['pending', 'approved', 'rejected', 'repaid'])->default('pending');
            $table->foreignId('approver_id')->nullable()->constrained('users')->onDelete('set null');
            $table->date('repayment_started_at')->nullable();
            $table->decimal('outstanding_balance', 10, 2)->default(0.00);
            $table->timestamps();
        });

        // 3. Timesheets
        Schema::create('timesheets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->date('date');
            $table->string('project_name');
            $table->text('task_description');
            $table->decimal('hours', 4, 2);
            $table->boolean('billable')->default(true);
            $table->enum('status', ['draft', 'submitted', 'approved', 'rejected'])->default('submitted');
            $table->foreignId('approver_id')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
        });

        // 4. Asset Management
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->string('asset_code')->unique();
            $table->string('name');
            $table->string('category'); // Laptop, Phone, Monitor, Headset, Peripheral
            $table->string('serial_number')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->onDelete('set null');
            $table->date('assigned_at')->nullable();
            $table->date('returned_at')->nullable();
            $table->enum('condition', ['new', 'good', 'fair', 'damaged'])->default('good');
            $table->enum('status', ['available', 'assigned', 'maintenance', 'retired'])->default('available');
            $table->timestamps();
        });

        // 5. Helpdesk Tickets
        Schema::create('helpdesk_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->string('ticket_number')->unique();
            $table->foreignId('requester_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->onDelete('set null');
            $table->string('category'); // Payroll Query, Profile Change, IT Support, Document Request, Leave Policy
            $table->enum('priority', ['low', 'medium', 'high', 'urgent'])->default('medium');
            $table->string('subject');
            $table->text('description');
            $table->enum('status', ['open', 'in_progress', 'resolved', 'closed'])->default('open');
            $table->text('resolution_notes')->nullable();
            $table->timestamps();
        });

        // 6. Centralized Notifications
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('title');
            $table->text('message');
            $table->string('type')->default('info'); // info, success, warning, task, leave, payroll
            $table->boolean('is_read')->default(false);
            $table->string('link')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('helpdesk_tickets');
        Schema::dropIfExists('assets');
        Schema::dropIfExists('timesheets');
        Schema::dropIfExists('loan_requests');
        Schema::dropIfExists('expense_claims');
    }
};
