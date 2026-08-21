<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Branches
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->string('name');
            $table->string('code')->unique();
            $table->text('address')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
        });

        // 2. Locations
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->string('name');
            $table->string('city');
            $table->string('state')->default('Maharashtra');
            $table->string('country')->default('India');
            $table->string('postal_code')->nullable();
            $table->timestamps();
        });

        // 3. Shifts
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->string('name'); // Morning Shift, General Shift, Night Shift
            $table->time('start_time')->default('09:00:00');
            $table->time('end_time')->default('18:00:00');
            $table->integer('grace_period_minutes')->default(15);
            $table->json('work_days')->nullable(); // ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday"]
            $table->timestamps();
        });

        // 4. Teams
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->foreignId('department_id')->nullable()->constrained('departments')->onDelete('set null');
            $table->foreignId('leader_id')->nullable()->constrained('users')->onDelete('set null');
            $table->string('name');
            $table->string('code')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // 5. Job Roles
        Schema::create('job_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->string('title');
            $table->string('code')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // 6. Permissions
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique(); // e.g. employee.view, payroll.process
            $table->string('display_name');
            $table->string('module'); // employee, attendance, leave, payroll, tasks, etc.
            $table->timestamps();
        });

        // 7. Role Permissions Mapping
        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained('roles')->onDelete('cascade');
            $table->foreignId('permission_id')->constrained('permissions')->onDelete('cascade');
            $table->timestamps();

            $table->unique(['role_id', 'permission_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('job_roles');
        Schema::dropIfExists('teams');
        Schema::dropIfExists('shifts');
        Schema::dropIfExists('locations');
        Schema::dropIfExists('branches');
    }
};
