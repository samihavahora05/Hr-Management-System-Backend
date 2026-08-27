<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Models\LeaveType;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\EmployeeDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LeaveAndDocumentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected $orgA;
    protected $orgB;
    protected $adminRole;
    protected $hrRole;
    protected $managerRole;
    protected $teamLeadRole;
    protected $empRole;

    protected $adminA;
    protected $hrA;
    protected $managerA;
    protected $empA;
    protected $empOtherManagerA;
    protected $empB;

    protected $leaveType;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->adminRole = Role::create(['name' => 'admin', 'display_name' => 'Admin']);
        $this->hrRole = Role::create(['name' => 'hr', 'display_name' => 'HR']);
        $this->managerRole = Role::create(['name' => 'manager', 'display_name' => 'Manager']);
        $this->teamLeadRole = Role::create(['name' => 'team_leader', 'display_name' => 'Team Leader']);
        $this->empRole = Role::create(['name' => 'employee', 'display_name' => 'Employee']);

        $this->orgA = Organization::create(['name' => 'Acme Corp', 'code' => 'ACME']);
        $this->orgB = Organization::create(['name' => 'Beta Corp', 'code' => 'BETA']);

        $this->adminA = User::create([
            'organization_id' => $this->orgA->id,
            'role_id' => $this->adminRole->id,
            'name' => 'Admin A',
            'email' => 'admin@acme.com',
            'password' => 'secret',
            'remember_token' => 'tok_adminA',
        ]);

        $this->hrA = User::create([
            'organization_id' => $this->orgA->id,
            'role_id' => $this->hrRole->id,
            'name' => 'HR A',
            'email' => 'hr@acme.com',
            'password' => 'secret',
            'remember_token' => 'tok_hrA',
        ]);

        $this->managerA = User::create([
            'organization_id' => $this->orgA->id,
            'role_id' => $this->managerRole->id,
            'name' => 'Manager A',
            'email' => 'manager@acme.com',
            'password' => 'secret',
            'remember_token' => 'tok_managerA',
        ]);

        $this->empA = User::create([
            'organization_id' => $this->orgA->id,
            'role_id' => $this->empRole->id,
            'name' => 'Emp A',
            'email' => 'emp@acme.com',
            'password' => 'secret',
            'manager_id' => $this->managerA->id,
            'remember_token' => 'tok_empA',
        ]);

        $this->empOtherManagerA = User::create([
            'organization_id' => $this->orgA->id,
            'role_id' => $this->empRole->id,
            'name' => 'Emp Other',
            'email' => 'other@acme.com',
            'password' => 'secret',
            'manager_id' => null,
            'remember_token' => 'tok_otherA',
        ]);

        $this->empB = User::create([
            'organization_id' => $this->orgB->id,
            'role_id' => $this->empRole->id,
            'name' => 'Emp B',
            'email' => 'emp@beta.com',
            'password' => 'secret',
            'remember_token' => 'tok_empB',
        ]);

        $this->leaveType = LeaveType::create([
            'organization_id' => $this->orgA->id,
            'name' => 'Casual Leave',
            'annual_quota' => 12,
            'is_paid' => true,
        ]);

        LeaveBalance::create([
            'organization_id' => $this->orgA->id,
            'user_id' => $this->empA->id,
            'leave_type_id' => $this->leaveType->id,
            'allocated' => 12,
            'used' => 0,
            'remaining' => 12,
        ]);
    }

    /** @test */
    public function employee_sees_own_leaves_only()
    {
        LeaveRequest::create([
            'organization_id' => $this->orgA->id,
            'user_id' => $this->empA->id,
            'leave_type_id' => $this->leaveType->id,
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-02',
            'days_count' => 2,
            'reason' => 'Emp A leave',
            'status' => 'pending',
        ]);

        LeaveRequest::create([
            'organization_id' => $this->orgA->id,
            'user_id' => $this->empOtherManagerA->id,
            'leave_type_id' => $this->leaveType->id,
            'start_date' => '2026-09-05',
            'end_date' => '2026-09-06',
            'days_count' => 2,
            'reason' => 'Other leave',
            'status' => 'pending',
        ]);

        $res = $this->withHeader('Authorization', 'Bearer tok_empA')
            ->getJson('/api/leave/requests');

        $res->assertStatus(200);
        $res->assertJsonCount(1, 'leave_requests');
        $res->assertJsonPath('leave_requests.0.user_id', $this->empA->id);
    }

    /** @test */
    public function employee_cannot_approve_or_reject_leave()
    {
        $req = LeaveRequest::create([
            'organization_id' => $this->orgA->id,
            'user_id' => $this->empOtherManagerA->id,
            'leave_type_id' => $this->leaveType->id,
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-02',
            'days_count' => 2,
            'reason' => 'Personal',
            'status' => 'pending',
        ]);

        $approveRes = $this->withHeader('Authorization', 'Bearer tok_empA')
            ->postJson("/api/leave/requests/{$req->id}/approve");

        $approveRes->assertStatus(403);
    }

    /** @test */
    public function employee_can_cancel_own_pending_leave()
    {
        $req = LeaveRequest::create([
            'organization_id' => $this->orgA->id,
            'user_id' => $this->empA->id,
            'leave_type_id' => $this->leaveType->id,
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-02',
            'days_count' => 2,
            'reason' => 'Vacation',
            'status' => 'pending',
        ]);

        $cancelRes = $this->withHeader('Authorization', 'Bearer tok_empA')
            ->postJson("/api/leave/requests/{$req->id}/cancel");

        $cancelRes->assertStatus(200);
        $this->assertEquals('cancelled', $req->fresh()->status);
    }

    /** @test */
    public function admin_can_approve_leave_request_for_any_user()
    {
        $req = LeaveRequest::create([
            'organization_id' => $this->orgA->id,
            'user_id' => $this->empA->id,
            'leave_type_id' => $this->leaveType->id,
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-02',
            'days_count' => 2,
            'reason' => 'Sick',
            'status' => 'pending',
        ]);

        $res = $this->withHeader('Authorization', 'Bearer tok_adminA')
            ->postJson("/api/leave/requests/{$req->id}/approve");

        $res->assertStatus(200);
        $this->assertEquals('approved', $req->fresh()->status);
        $this->assertEquals(10, LeaveBalance::where('user_id', $this->empA->id)->first()->remaining);
    }

    /** @test */
    public function non_admin_roles_cannot_approve_leave_requests()
    {
        $req = LeaveRequest::create([
            'organization_id' => $this->orgA->id,
            'user_id' => $this->empA->id,
            'leave_type_id' => $this->leaveType->id,
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-02',
            'days_count' => 2,
            'reason' => 'Sick',
            'status' => 'pending',
        ]);

        // Manager attempting to approve -> 403
        $mgrRes = $this->withHeader('Authorization', 'Bearer tok_managerA')
            ->postJson("/api/leave/requests/{$req->id}/approve");
        $mgrRes->assertStatus(403);

        // HR attempting to approve -> 403
        $hrRes = $this->withHeader('Authorization', 'Bearer tok_hrA')
            ->postJson("/api/leave/requests/{$req->id}/approve");
        $hrRes->assertStatus(403);
    }

    /** @test */
    public function cross_organization_leave_approval_blocked()
    {
        $req = LeaveRequest::create([
            'organization_id' => $this->orgA->id,
            'user_id' => $this->empA->id,
            'leave_type_id' => $this->leaveType->id,
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-02',
            'days_count' => 2,
            'reason' => 'Sick',
            'status' => 'pending',
        ]);

        $res = $this->withHeader('Authorization', 'Bearer tok_empB')
            ->postJson("/api/leave/requests/{$req->id}/approve");

        $res->assertStatus(404);
    }

    /** @test */
    public function multipart_document_upload_and_download()
    {
        $fakeFile = UploadedFile::fake()->create('contract.pdf', 500, 'application/pdf');

        $uploadRes = $this->withHeader('Authorization', 'Bearer tok_empA')
            ->postJson('/api/documents', [
                'title' => 'Signed Employment Contract',
                'type' => 'contract',
                'file' => $fakeFile,
            ]);

        $uploadRes->assertStatus(201);
        $docId = $uploadRes->json('document.id');
        $storedPath = $uploadRes->json('document.file_url');

        $this->assertNotEmpty($storedPath);
        Storage::disk('local')->assertExists($storedPath);

        // Download by owner succeeds
        $downRes = $this->withHeader('Authorization', 'Bearer tok_empA')
            ->get("/api/documents/{$docId}/download");

        $downRes->assertStatus(200);

        // Download by unrelated user in Org B fails with 404
        $crossRes = $this->withHeader('Authorization', 'Bearer tok_empB')
            ->get("/api/documents/{$docId}/download");

        $crossRes->assertStatus(404);
    }
}
