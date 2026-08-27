<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->constrained('branches')->onDelete('set null');
            $table->foreignId('location_id')->nullable()->constrained('locations')->onDelete('set null');
            $table->foreignId('shift_id')->nullable()->constrained('shifts')->onDelete('set null');
            $table->foreignId('team_id')->nullable()->constrained('teams')->onDelete('set null');
            $table->foreignId('job_role_id')->nullable()->constrained('job_roles')->onDelete('set null');
            
            $table->string('gender')->nullable();
            $table->date('dob')->nullable();
            $table->enum('work_mode', ['office', 'remote', 'hybrid'])->default('office');
            $table->enum('probation_status', ['probation', 'confirmed', 'extended'])->default('probation');
            $table->date('confirmation_date')->nullable();
            
            $table->json('emergency_contact')->nullable(); // { name, relationship, phone }
            $table->string('pan')->nullable();
            $table->json('bank_details')->nullable(); // { account_number, ifsc_code, bank_name, branch }
            $table->string('pf_number')->nullable();
            $table->string('esi_number')->nullable();
            $table->enum('tax_regime', ['new', 'old'])->default('new');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['branch_id']);
            $table->dropForeign(['location_id']);
            $table->dropForeign(['shift_id']);
            $table->dropForeign(['team_id']);
            $table->dropForeign(['job_role_id']);
            
            $table->dropColumn([
                'branch_id', 'location_id', 'shift_id', 'team_id', 'job_role_id',
                'gender', 'dob', 'work_mode', 'probation_status', 'confirmation_date',
                'emergency_contact', 'pan', 'bank_details', 'pf_number', 'esi_number', 'tax_regime'
            ]);
        });
    }
};
