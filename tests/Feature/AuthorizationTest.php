<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Models\LeaveType;
use App\Models\LeaveRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected $orgA;
    protected $orgB;
    protected $adminRole;
    protected $hrRole;
    protected $managerRole;
    protected $empRole;

    protected $adminA;
    protected $hrA;
    protected $manager1A;
    protected $manager2A;
    protected $emp1A; // reports to manager1A
    protected $emp2A; // reports to manager2A

    protected $empB; // belongs to orgB

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Roles
        $this->adminRole = Role::create(['name' => 'admin', 'display_name' => 'Admin']);
        $this->hrRole = Role::create(['name' => 'hr', 'display_name' => 'HR']);
        $this->managerRole = Role::create(['name' => 'manager', 'display_name' => 'Manager']);
        $this->empRole = Role::create(['name' => 'employee', 'display_name' => 'Employee']);

        // 2. Organizations
        $this->orgA = Organization::create(['name' => 'Org A', 'code' => 'ORGA']);
        $this->orgB = Organization::create(['name' => 'Org B', 'code' => 'ORGB']);

        // 3. Users in Org A
        $this->adminA = User::create([
            'organization_id' => $this->orgA->id,
            'role_id' => $this->adminRole->id,
            'name' => 'Admin A',
            'email' => 'adminA@test.com',
            'password' => 'secret',
            'remember_token' => 'token_adminA',
        ]);

        $this->hrA = User::create([
            'organization_id' => $this->orgA->id,
            'role_id' => $this->hrRole->id,
            'name' => 'HR A',
            'email' => 'hrA@test.com',
            'password' => 'secret',
            'remember_token' => 'token_hrA',
        ]);

        $this->manager1A = User::create([
            'organization_id' => $this->orgA->id,
            'role_id' => $this->managerRole->id,
            'name' => 'Manager 1A',
            'email' => 'manager1A@test.com',
            'password' => 'secret',
            'remember_token' => 'token_manager1A',
        ]);

        $this->manager2A = User::create([
            'organization_id' => $this->orgA->id,
            'role_id' => $this->managerRole->id,
            'name' => 'Manager 2A',
            'email' => 'manager2A@test.com',
            'password' => 'secret',
            'remember_token' => 'token_manager2A',
        ]);

        $this->emp1A = User::create([
            'organization_id' => $this->orgA->id,
            'role_id' => $this->empRole->id,
            'name' => 'Emp 1A',
            'email' => 'emp1A@test.com',
            'password' => 'secret',
            'manager_id' => $this->manager1A->id,
            'remember_token' => 'token_emp1A',
        ]);

        $this->emp2A = User::create([
            'organization_id' => $this->orgA->id,
            'role_id' => $this->empRole->id,
            'name' => 'Emp 2A',
            'email' => 'emp2A@test.com',
            'password' => 'secret',
            'manager_id' => $this->manager2A->id,
            'remember_token' => 'token_emp2A',
        ]);

        // 4. User in Org B
        $this->empB = User::create([
            'organization_id' => $this->orgB->id,
            'role_id' => $this->empRole->id,
            'name' => 'Emp B',
            'email' => 'empB@test.com',
            'password' => 'secret',
            'remember_token' => 'token_empB',
        ]);
    }

    /** @test */
    public function employee_cannot_access_another_employee_profile()
    {
        $response = $this->withHeader('Authorization', 'Bearer token_emp1A')
            ->getJson("/api/employees/{$this->emp2A->id}");

        $response->assertStatus(403);
    }

    /** @test */
    public function employee_can_access_own_profile()
    {
        $response = $this->withHeader('Authorization', 'Bearer token_emp1A')
            ->getJson("/api/employees/{$this->emp1A->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('employee.id', $this->emp1A->id);
    }

    /** @test */
    public function manager_cannot_access_another_managers_employee()
    {
        $response = $this->withHeader('Authorization', 'Bearer token_manager1A')
            ->getJson("/api/employees/{$this->emp2A->id}");

        $response->assertStatus(403);
    }

    /** @test */
    public function manager_can_access_direct_report_employee()
    {
        $response = $this->withHeader('Authorization', 'Bearer token_manager1A')
            ->getJson("/api/employees/{$this->emp1A->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('employee.id', $this->emp1A->id);
    }

    /** @test */
    public function manager_cannot_approve_another_managers_employee_leave()
    {
        $leaveType = LeaveType::create(['organization_id' => $this->orgA->id, 'name' => 'Casual']);
        $leaveRequest = LeaveRequest::create([
            'organization_id' => $this->orgA->id,
            'user_id' => $this->emp2A->id, // reports to manager2A
            'leave_type_id' => $leaveType->id,
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-02',
            'days_count' => 2,
            'reason' => 'Personal',
            'status' => 'pending',
        ]);

        // Manager 1A tries to approve Manager 2A's employee's leave
        $response = $this->withHeader('Authorization', 'Bearer token_manager1A')
            ->postJson("/api/leave/requests/{$leaveRequest->id}/approve");

        $response->assertStatus(403);
    }

    /** @test */
    public function hr_cannot_access_admin_only_settings()
    {
        $response = $this->withHeader('Authorization', 'Bearer token_hrA')
            ->getJson('/api/settings/organization');

        $response->assertStatus(403);
    }

    /** @test */
    public function admin_can_access_organization_settings()
    {
        $response = $this->withHeader('Authorization', 'Bearer token_adminA')
            ->getJson('/api/settings/organization');

        $response->assertStatus(200);
        $response->assertJsonPath('organization.name', 'Org A');
    }

    /** @test */
    public function employee_cannot_create_employees()
    {
        $resStore = $this->withHeader('Authorization', 'Bearer token_emp1A')
            ->postJson('/api/employees', [
                'name' => 'New Guy',
                'email' => 'newguy@test.com',
                'role' => 'employee',
                'department' => 'Engineering',
                'designation' => 'Dev',
                'joining_date' => '2026-08-01',
                'base_salary' => 50000,
            ]);

        $resStore->assertStatus(403);
    }

    /** @test */
    public function cross_organization_access_returns_forbidden_or_not_found()
    {
        $response = $this->withHeader('Authorization', 'Bearer token_empB')
            ->getJson("/api/employees/{$this->emp1A->id}");

        $response->assertStatus(404);
    }
}
