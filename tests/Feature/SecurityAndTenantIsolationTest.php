<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Models\EmployeeDocument;
use App\Models\LeaveType;
use App\Models\LeaveRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SecurityAndTenantIsolationTest extends TestCase
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
    protected $managerA;
    protected $emp1A;
    protected $emp2A;

    protected $adminB;
    protected $empB;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->adminRole = Role::create(['name' => 'admin', 'display_name' => 'Administrator']);
        $this->hrRole = Role::create(['name' => 'hr', 'display_name' => 'Human Resources']);
        $this->managerRole = Role::create(['name' => 'manager', 'display_name' => 'Manager']);
        $this->empRole = Role::create(['name' => 'employee', 'display_name' => 'Employee']);

        $this->orgA = Organization::create(['name' => 'Alpha Holdings', 'code' => 'ALPHA']);
        $this->orgB = Organization::create(['name' => 'Beta Logistics', 'code' => 'BETA']);

        $this->adminA = User::create([
            'organization_id' => $this->orgA->id,
            'role_id' => $this->adminRole->id,
            'name' => 'Admin Alpha',
            'email' => 'admin@alpha.com',
            'password' => 'secret123',
            'status' => 'active',
            'remember_token' => 'tok_admin_alpha',
        ]);

        $this->hrA = User::create([
            'organization_id' => $this->orgA->id,
            'role_id' => $this->hrRole->id,
            'name' => 'HR Alpha',
            'email' => 'hr@alpha.com',
            'password' => 'secret123',
            'status' => 'active',
            'remember_token' => 'tok_hr_alpha',
        ]);

        $this->managerA = User::create([
            'organization_id' => $this->orgA->id,
            'role_id' => $this->managerRole->id,
            'name' => 'Manager Alpha',
            'email' => 'manager@alpha.com',
            'password' => 'secret123',
            'status' => 'active',
            'remember_token' => 'tok_manager_alpha',
        ]);

        $this->emp1A = User::create([
            'organization_id' => $this->orgA->id,
            'role_id' => $this->empRole->id,
            'name' => 'Emp 1 Alpha',
            'email' => 'emp1@alpha.com',
            'password' => 'secret123',
            'status' => 'active',
            'manager_id' => $this->managerA->id,
            'base_salary' => 75000,
            'remember_token' => 'tok_emp1_alpha',
        ]);

        $this->emp2A = User::create([
            'organization_id' => $this->orgA->id,
            'role_id' => $this->empRole->id,
            'name' => 'Emp 2 Alpha',
            'email' => 'emp2@alpha.com',
            'password' => 'secret123',
            'status' => 'active',
            'base_salary' => 80000,
            'remember_token' => 'tok_emp2_alpha',
        ]);

        $this->adminB = User::create([
            'organization_id' => $this->orgB->id,
            'role_id' => $this->adminRole->id,
            'name' => 'Admin Beta',
            'email' => 'admin@beta.com',
            'password' => 'secret123',
            'status' => 'active',
            'remember_token' => 'tok_admin_beta',
        ]);

        $this->empB = User::create([
            'organization_id' => $this->orgB->id,
            'role_id' => $this->empRole->id,
            'name' => 'Emp Beta',
            'email' => 'emp@beta.com',
            'password' => 'secret123',
            'status' => 'active',
            'remember_token' => 'tok_emp_beta',
        ]);
    }

    /** @test */
    public function unauthenticated_requests_are_denied()
    {
        $res = $this->getJson('/api/auth/me');
        $res->assertStatus(401);

        $resEmp = $this->getJson('/api/employees');
        $resEmp->assertStatus(401);

        $resDoc = $this->getJson('/api/documents');
        $resDoc->assertStatus(401);
    }

    /** @test */
    public function server_side_rbac_blocks_unauthorized_roles_from_admin_endpoints()
    {
        // Employee attempting to access audit logs
        $resAudit = $this->withHeader('Authorization', 'Bearer tok_emp1_alpha')
            ->getJson('/api/admin/audit-logs');
        $resAudit->assertStatus(403);

        // HR attempting to access organization settings
        $resSettings = $this->withHeader('Authorization', 'Bearer tok_hr_alpha')
            ->getJson('/api/settings/organization');
        $resSettings->assertStatus(403);

        // Employee attempting to create a new employee
        $resCreateEmp = $this->withHeader('Authorization', 'Bearer tok_emp1_alpha')
            ->postJson('/api/employees', [
                'name' => 'Hacker Bob',
                'email' => 'hacker@alpha.com',
                'role' => 'admin',
                'department' => 'Engineering',
                'designation' => 'Dev',
                'joining_date' => '2026-08-01',
                'base_salary' => 100000,
            ]);
        $resCreateEmp->assertStatus(403);
    }

    /** @test */
    public function cross_tenant_data_access_is_strictly_isolated()
    {
        // User in Org B attempts to access Org A's employee profile
        $res = $this->withHeader('Authorization', 'Bearer tok_emp_beta')
            ->getJson("/api/employees/{$this->emp1A->id}");
        $res->assertStatus(404);

        // Admin in Org B attempts to delete Org A's employee
        $resDel = $this->withHeader('Authorization', 'Bearer tok_admin_beta')
            ->deleteJson("/api/employees/{$this->emp1A->id}");
        $resDel->assertStatus(404);
    }

    /** @test */
    public function idor_protection_blocks_peer_employee_profile_access()
    {
        // Employee 1 attempts to access Employee 2's profile in the same organization
        $res = $this->withHeader('Authorization', 'Bearer tok_emp1_alpha')
            ->getJson("/api/employees/{$this->emp2A->id}");

        $res->assertStatus(403);
    }

    /** @test */
    public function sensitive_financial_fields_are_hidden_from_peers()
    {
        // Manager viewing their direct report should not see unmasked base_salary
        $res = $this->withHeader('Authorization', 'Bearer tok_manager_alpha')
            ->getJson("/api/employees/{$this->emp1A->id}");

        $res->assertStatus(200);
        $res->assertJsonMissing(['base_salary' => '75000.00']);
    }

    /** @test */
    public function secure_document_access_blocks_unauthorized_downloads()
    {
        $fakeFile = UploadedFile::fake()->create('id_proof.pdf', 300, 'application/pdf');

        $upRes = $this->withHeader('Authorization', 'Bearer tok_emp1_alpha')
            ->postJson('/api/documents', [
                'title' => 'Passport Scan',
                'type' => 'id_proof',
                'file' => $fakeFile,
            ]);
        $upRes->assertStatus(201);
        $docId = $upRes->json('document.id');

        // Peer employee in same org attempts to download document -> 403
        $peerRes = $this->withHeader('Authorization', 'Bearer tok_emp2_alpha')
            ->get("/api/documents/{$docId}/download");
        $peerRes->assertStatus(403);

        // User in Org B attempts to download document -> 404
        $crossRes = $this->withHeader('Authorization', 'Bearer tok_emp_beta')
            ->get("/api/documents/{$docId}/download");
        $crossRes->assertStatus(404);

        // Owner downloads document -> 200
        $ownerRes = $this->withHeader('Authorization', 'Bearer tok_emp1_alpha')
            ->get("/api/documents/{$docId}/download");
        $ownerRes->assertStatus(200);
    }
}
