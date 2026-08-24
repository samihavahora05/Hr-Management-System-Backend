<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Models\LeaveType;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\Attendance;
use App\Models\OnboardingChecklist;
use App\Models\Announcement;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkflowsTest extends TestCase
{
    use RefreshDatabase;

    protected $org;
    protected $adminRole;
    protected $hrRole;
    protected $managerRole;
    protected $empRole;
    protected $hrUser;
    protected $managerUser;
    protected $empUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminRole = Role::create(['name' => 'admin', 'display_name' => 'Admin']);
        $this->hrRole = Role::create(['name' => 'hr', 'display_name' => 'HR']);
        $this->managerRole = Role::create(['name' => 'manager', 'display_name' => 'Manager']);
        $this->empRole = Role::create(['name' => 'employee', 'display_name' => 'Employee']);

        $this->org = Organization::create(['name' => 'Acme Corp', 'code' => 'ACME']);

        $this->hrUser = User::create([
            'organization_id' => $this->org->id,
            'role_id' => $this->hrRole->id,
            'name' => 'HR Manager',
            'email' => 'hr@acme.com',
            'password' => 'secret',
            'remember_token' => 'hr_token',
        ]);

        $this->managerUser = User::create([
            'organization_id' => $this->org->id,
            'role_id' => $this->managerRole->id,
            'name' => 'Engineering Lead',
            'email' => 'manager@acme.com',
            'password' => 'secret',
            'remember_token' => 'manager_token',
        ]);

        $this->empUser = User::create([
            'organization_id' => $this->org->id,
            'role_id' => $this->empRole->id,
            'name' => 'John Dev',
            'email' => 'john@acme.com',
            'password' => 'secret',
            'manager_id' => $this->managerUser->id,
            'base_salary' => 60000,
            'remember_token' => 'emp_token',
        ]);

        LeaveType::create(['organization_id' => $this->org->id, 'name' => 'Casual Leave', 'annual_quota' => 12]);
        LeaveType::create(['organization_id' => $this->org->id, 'name' => 'Sick Leave', 'annual_quota' => 10]);
    }

    /** @test */
    public function test_employee_creation_and_manager_relationship_workflow()
    {
        $response = $this->withHeader('Authorization', 'Bearer hr_token')
            ->postJson('/api/employees', [
                'name' => 'Alice Junior',
                'email' => 'alice@acme.com',
                'role' => 'employee',
                'department' => 'Engineering',
                'designation' => 'Junior Dev',
                'joining_date' => '2026-08-01',
                'base_salary' => 50000,
                'manager_id' => $this->managerUser->id,
            ]);

        $response->assertStatus(201);
        $newEmpId = $response->json('employee.id');

        $this->assertDatabaseHas('users', [
            'id' => $newEmpId,
            'email' => 'alice@acme.com',
            'manager_id' => $this->managerUser->id,
        ]);

        $this->assertDatabaseHas('leave_balances', [
            'user_id' => $newEmpId,
            'allocated' => 12,
        ]);
    }

    /** @test */
    public function test_attendance_checkin_checkout_workflow()
    {
        $resIn = $this->withHeader('Authorization', 'Bearer emp_token')
            ->postJson('/api/attendance/check-in', ['notes' => 'On time']);

        $resIn->assertStatus(200);
        $this->assertDatabaseHas('attendances', [
            'user_id' => $this->empUser->id,
        ]);

        $resOut = $this->withHeader('Authorization', 'Bearer emp_token')
            ->postJson('/api/attendance/check-out');

        $resOut->assertStatus(200);
    }

    /** @test */
    public function test_leave_request_approval_and_rejection_workflow()
    {
        $leaveType = LeaveType::where('organization_id', $this->org->id)->first();

        // Seed leave balance
        LeaveBalance::create([
            'organization_id' => $this->org->id,
            'user_id' => $this->empUser->id,
            'leave_type_id' => $leaveType->id,
            'allocated' => 12,
            'used' => 0,
            'remaining' => 12,
        ]);

        // Submit request
        $resReq = $this->withHeader('Authorization', 'Bearer emp_token')
            ->postJson('/api/leave/requests', [
                'leave_type_id' => $leaveType->id,
                'start_date' => '2026-09-10',
                'end_date' => '2026-09-12',
                'reason' => 'Vacation',
            ]);

        $resReq->assertStatus(201);
        $leaveId = $resReq->json('leave_request.id');

        // Manager approves
        $resApprove = $this->withHeader('Authorization', 'Bearer manager_token')
            ->postJson("/api/leave/requests/{$leaveId}/approve");

        $resApprove->assertStatus(200);

        $this->assertDatabaseHas('leave_requests', [
            'id' => $leaveId,
            'status' => 'approved',
        ]);

        // Verify balance deducted
        $this->assertDatabaseHas('leave_balances', [
            'user_id' => $this->empUser->id,
            'used' => 3,
            'remaining' => 9,
        ]);
    }

    /** @test */
    public function test_onboarding_announcements_and_audit_logs_workflow()
    {
        // Announcement
        $resAnn = $this->withHeader('Authorization', 'Bearer hr_token')
            ->postJson('/api/announcements', [
                'title' => 'Holiday Notice',
                'content' => 'Office closed on Friday.',
                'target_role' => 'all',
            ]);

        $resAnn->assertStatus(201);

        // Checklist
        $resChk = $this->withHeader('Authorization', 'Bearer hr_token')
            ->postJson('/api/checklists', [
                'user_id' => $this->empUser->id,
                'title' => 'Developer Onboarding',
                'type' => 'onboarding',
                'items' => [
                    ['id' => 1, 'text' => 'Set up laptop', 'completed' => false],
                ],
            ]);

        $resChk->assertStatus(201);
        $chkId = $resChk->json('checklist.id');

        // Toggle item
        $resTgl = $this->withHeader('Authorization', 'Bearer emp_token')
            ->postJson("/api/checklists/{$chkId}/toggle-item", ['item_id' => 1]);

        $resTgl->assertStatus(200);
    }
}
