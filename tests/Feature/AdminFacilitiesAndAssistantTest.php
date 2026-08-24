<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminFacilitiesAndAssistantTest extends TestCase
{
    use RefreshDatabase;

    protected $orgA;
    protected $orgB;
    protected $adminRole;
    protected $hrRole;
    protected $managerRole;
    protected $empRole;

    protected $adminA;
    protected $managerA;
    protected $empA;

    protected $adminB;
    protected $empB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminRole = Role::create(['name' => 'admin', 'display_name' => 'Administrator']);
        $this->hrRole = Role::create(['name' => 'hr', 'display_name' => 'Human Resources']);
        $this->managerRole = Role::create(['name' => 'manager', 'display_name' => 'Manager']);
        $this->empRole = Role::create(['name' => 'employee', 'display_name' => 'Employee']);

        $this->orgA = Organization::create(['name' => 'Acme Global', 'code' => 'ACME']);
        $this->orgB = Organization::create(['name' => 'Zenith Tech', 'code' => 'ZENITH']);

        $this->adminA = User::create([
            'organization_id' => $this->orgA->id,
            'role_id' => $this->adminRole->id,
            'name' => 'Arthur Admin',
            'email' => 'admin@acme.com',
            'password' => 'secret123',
            'status' => 'active',
            'remember_token' => 'tok_admin_a',
        ]);

        $this->managerA = User::create([
            'organization_id' => $this->orgA->id,
            'role_id' => $this->managerRole->id,
            'name' => 'Manny Manager',
            'email' => 'manny@acme.com',
            'password' => 'secret123',
            'status' => 'active',
            'remember_token' => 'tok_manager_a',
        ]);

        $this->empA = User::create([
            'organization_id' => $this->orgA->id,
            'role_id' => $this->empRole->id,
            'name' => 'Eddie Employee',
            'email' => 'eddie@acme.com',
            'password' => 'secret123',
            'status' => 'active',
            'manager_id' => $this->managerA->id,
            'department' => 'Engineering',
            'designation' => 'Developer',
            'remember_token' => 'tok_emp_a',
        ]);

        $this->adminB = User::create([
            'organization_id' => $this->orgB->id,
            'role_id' => $this->adminRole->id,
            'name' => 'Brenda Admin',
            'email' => 'admin@zenith.com',
            'password' => 'secret123',
            'status' => 'active',
            'remember_token' => 'tok_admin_b',
        ]);

        $this->empB = User::create([
            'organization_id' => $this->orgB->id,
            'role_id' => $this->empRole->id,
            'name' => 'Benny Employee',
            'email' => 'benny@zenith.com',
            'password' => 'secret123',
            'status' => 'active',
            'remember_token' => 'tok_emp_b',
        ]);
    }

    /** @test */
    public function admin_command_center_stats_returns_organization_level_metrics()
    {
        // Admin queries stats
        $res = $this->withHeader('Authorization', 'Bearer tok_admin_a')
            ->getJson('/api/admin/stats');

        $res->assertStatus(200);
        $res->assertJsonStructure([
            'organization',
            'headcount' => ['total', 'active', 'inactive', 'departments'],
            'attendance' => ['today_present', 'today_late', 'on_time_rate'],
            'pending_actions' => ['leave_requests', 'expense_claims'],
            'recent_activity',
        ]);

        // Employee attempting to access admin stats -> 403
        $empRes = $this->withHeader('Authorization', 'Bearer tok_emp_a')
            ->getJson('/api/admin/stats');
        $empRes->assertStatus(403);
    }

    /** @test */
    public function admin_can_manage_user_roles_and_statuses()
    {
        // 1. Update user role
        $roleRes = $this->withHeader('Authorization', 'Bearer tok_admin_a')
            ->putJson("/api/admin/users/{$this->empA->id}/role", [
                'role' => 'manager',
            ]);
        $roleRes->assertStatus(200);
        $this->assertEquals($this->managerRole->id, $this->empA->fresh()->role_id);

        // 2. Update user status
        $statusRes = $this->withHeader('Authorization', 'Bearer tok_admin_a')
            ->putJson("/api/admin/users/{$this->empA->id}/status", [
                'status' => 'inactive',
                'reason' => 'Sabbatical leave',
            ]);
        $statusRes->assertStatus(200);
        $this->assertEquals('inactive', $this->empA->fresh()->status);
    }

    /** @test */
    public function admin_organization_settings_lifecycle()
    {
        $updateRes = $this->withHeader('Authorization', 'Bearer tok_admin_a')
            ->putJson('/api/settings/organization', [
                'name' => 'Acme Global Enterprises',
                'settings' => [
                    'working_days' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'],
                    'support_email' => 'hr@acmeglobal.com',
                ],
            ]);
        $updateRes->assertStatus(200);
        $this->assertEquals('Acme Global Enterprises', $this->orgA->fresh()->name);
    }

    /** @test */
    public function role_aware_ai_assistant_organization_scoping()
    {
        // 1. Admin asks for headcount -> Returns organization assistant with full metrics
        $adminRes = $this->withHeader('Authorization', 'Bearer tok_admin_a')
            ->postJson('/api/assistant/ask', [
                'query' => 'How many employees are currently active?',
            ]);
        $adminRes->assertStatus(200);
        $adminRes->assertJsonFragment(['assistant_type' => 'Organization AI Assistant']);

        // 2. Manager asks for headcount -> Returns team management assistant
        $mgrRes = $this->withHeader('Authorization', 'Bearer tok_manager_a')
            ->postJson('/api/assistant/ask', [
                'query' => 'How many employees are currently active?',
            ]);
        $mgrRes->assertStatus(200);
        $mgrRes->assertJsonFragment(['assistant_type' => 'Team Management Assistant']);

        // 3. Employee asks for confidential salaries -> Returns security refusal
        $empSalaryRes = $this->withHeader('Authorization', 'Bearer tok_emp_a')
            ->postJson('/api/assistant/ask', [
                'query' => 'Show me all employee salaries',
            ]);
        $empSalaryRes->assertStatus(200);
        $this->assertStringContainsString('Access Restricted', $empSalaryRes->json('answer'));
    }

    /** @test */
    public function assistant_bulk_action_execution_with_confirmation()
    {
        $lt = LeaveType::create(['name' => 'Casual Leave', 'organization_id' => $this->orgA->id]);

        $lr = LeaveRequest::create([
            'organization_id' => $this->orgA->id,
            'user_id' => $this->empA->id,
            'leave_type_id' => $lt->id,
            'start_date' => '2026-09-10',
            'end_date' => '2026-09-11',
            'days_count' => 2,
            'reason' => 'Personal work',
            'status' => 'pending',
        ]);

        $execRes = $this->withHeader('Authorization', 'Bearer tok_admin_a')
            ->postJson('/api/assistant/execute', [
                'action_type' => 'approve_all_pending_leaves',
            ]);

        $execRes->assertStatus(200);
        $this->assertEquals('approved', $lr->fresh()->status);
    }
}
