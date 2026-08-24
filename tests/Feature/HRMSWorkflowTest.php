<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Models\LeaveType;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\Attendance;
use App\Models\JobOpening;
use App\Models\Candidate;
use App\Models\ExpenseClaim;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;
use Tests\TestCase;

class HRMSWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected $org;
    protected $adminRole;
    protected $hrRole;
    protected $managerRole;
    protected $empRole;

    protected $admin;
    protected $hr;
    protected $manager;
    protected $employee;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->adminRole = Role::create(['name' => 'admin', 'display_name' => 'Administrator']);
        $this->hrRole = Role::create(['name' => 'hr', 'display_name' => 'Human Resources']);
        $this->managerRole = Role::create(['name' => 'manager', 'display_name' => 'Manager']);
        $this->empRole = Role::create(['name' => 'employee', 'display_name' => 'Employee']);

        $this->org = Organization::create(['name' => 'Acme Corp', 'code' => 'ACME']);

        $this->admin = User::create([
            'organization_id' => $this->org->id,
            'role_id' => $this->adminRole->id,
            'name' => 'Alice Admin',
            'email' => 'alice@acme.com',
            'password' => 'secret123',
            'status' => 'active',
            'remember_token' => 'tok_admin',
        ]);

        $this->hr = User::create([
            'organization_id' => $this->org->id,
            'role_id' => $this->hrRole->id,
            'name' => 'Hannah HR',
            'email' => 'hannah@acme.com',
            'password' => 'secret123',
            'status' => 'active',
            'remember_token' => 'tok_hr',
        ]);

        $this->manager = User::create([
            'organization_id' => $this->org->id,
            'role_id' => $this->managerRole->id,
            'name' => 'Mark Manager',
            'email' => 'mark@acme.com',
            'password' => 'secret123',
            'status' => 'active',
            'remember_token' => 'tok_manager',
        ]);

        $this->employee = User::create([
            'organization_id' => $this->org->id,
            'role_id' => $this->empRole->id,
            'name' => 'Evan Employee',
            'email' => 'evan@acme.com',
            'password' => 'secret123',
            'status' => 'active',
            'manager_id' => $this->manager->id,
            'department' => 'Engineering',
            'designation' => 'Software Engineer',
            'base_salary' => 80000,
            'remember_token' => 'tok_employee',
        ]);
    }

    /** @test */
    public function employee_search_and_filtering_works()
    {
        $res = $this->withHeader('Authorization', 'Bearer tok_admin')
            ->getJson('/api/employees?search=Evan&department=Engineering');

        $res->assertStatus(200);
        $res->assertJsonFragment(['name' => 'Evan Employee']);
    }

    /** @test */
    public function attendance_clock_in_and_clock_out_workflow_works()
    {
        // 1. Clock In
        $inRes = $this->withHeader('Authorization', 'Bearer tok_employee')
            ->postJson('/api/attendance/check-in', [
                'time' => '09:05:00',
                'work_mode' => 'office',
            ]);
        $inRes->assertStatus(200);

        // 2. Prevent duplicate Clock In
        $dupRes = $this->withHeader('Authorization', 'Bearer tok_employee')
            ->postJson('/api/attendance/check-in', [
                'time' => '09:10:00',
            ]);
        $dupRes->assertStatus(400);

        // 3. Clock Out
        $outRes = $this->withHeader('Authorization', 'Bearer tok_employee')
            ->postJson('/api/attendance/check-out', [
                'time' => '17:30:00',
            ]);
        $outRes->assertStatus(200);

        // 4. Check summary
        $sumRes = $this->withHeader('Authorization', 'Bearer tok_employee')
            ->getJson('/api/attendance/summary');
        $sumRes->assertStatus(200);
        $sumRes->assertJsonStructure(['present', 'days_in_month']);
    }

    /** @test */
    public function recruitment_full_lifecycle_workflow_works()
    {
        // 1. Create Job Opening
        $openRes = $this->withHeader('Authorization', 'Bearer tok_hr')
            ->postJson('/api/recruitment/openings', [
                'title' => 'DevOps Engineer',
                'department' => 'Engineering',
                'vacancies' => 1,
                'description' => 'Looking for AWS & Docker expert',
            ]);
        $openRes->assertStatus(201);
        $openingId = $openRes->json('opening.id');

        // 2. Add Candidate
        $candRes = $this->withHeader('Authorization', 'Bearer tok_hr')
            ->postJson('/api/recruitment/candidates', [
                'job_opening_id' => $openingId,
                'name' => 'Charlie Candidate',
                'email' => 'charlie@applicant.com',
                'phone' => '+91 9988776655',
            ]);
        $candRes->assertStatus(201);
        $candId = $candRes->json('candidate.id');

        // 3. Schedule Interview
        $intRes = $this->withHeader('Authorization', 'Bearer tok_hr')
            ->postJson('/api/recruitment/interviews', [
                'candidate_id' => $candId,
                'interviewer_id' => $this->manager->id,
                'scheduled_at' => Carbon::tomorrow()->format('Y-m-d 10:00:00'),
                'round' => 'Technical Round 1',
            ]);
        $intRes->assertStatus(201);

        // 4. Onboard Candidate to Master Employee Record
        $onboardRes = $this->withHeader('Authorization', 'Bearer tok_hr')
            ->postJson("/api/recruitment/candidates/{$candId}/onboard", [
                'salary_offered' => 95000,
                'joining_date' => Carbon::tomorrow()->toDateString(),
                'department' => 'Engineering',
                'designation' => 'DevOps Engineer',
                'manager_id' => $this->manager->id,
            ]);
        $onboardRes->assertStatus(200);

        // Verify converted user exists
        $this->assertDatabaseHas('users', [
            'email' => 'charlie@applicant.com',
            'organization_id' => $this->org->id,
            'department' => 'Engineering',
        ]);
    }

    /** @test */
    public function expense_management_workflow_works()
    {
        $fakeReceipt = UploadedFile::fake()->create('hotel_bill.pdf', 200, 'application/pdf');

        // 1. Submit Expense
        $expRes = $this->withHeader('Authorization', 'Bearer tok_employee')
            ->postJson('/api/expenses', [
                'title' => 'Client Lunch Meeting',
                'category' => 'food',
                'amount' => 2450.00,
                'date' => Carbon::yesterday()->toDateString(),
                'description' => 'Lunch with enterprise client',
                'receipt' => $fakeReceipt,
            ]);
        $expRes->assertStatus(201);
        $expId = $expRes->json('expense.id');

        // 2. HR approves expense
        $appRes = $this->withHeader('Authorization', 'Bearer tok_hr')
            ->postJson("/api/expenses/{$expId}/approve");
        $appRes->assertStatus(200);
        $appRes->assertJsonFragment(['status' => 'approved']);
    }

    /** @test */
    public function reports_and_analytics_endpoints_return_scoped_data()
    {
        $headcountRes = $this->withHeader('Authorization', 'Bearer tok_admin')
            ->getJson('/api/reports/headcount');
        $headcountRes->assertStatus(200);
        $headcountRes->assertJsonStructure(['summary', 'by_department', 'by_role']);

        $trendsRes = $this->withHeader('Authorization', 'Bearer tok_admin')
            ->getJson('/api/reports/attendance-trends');
        $trendsRes->assertStatus(200);
        $trendsRes->assertJsonStructure(['trends']);

        $recruitmentRes = $this->withHeader('Authorization', 'Bearer tok_admin')
            ->getJson('/api/reports/recruitment');
        $recruitmentRes->assertStatus(200);
        $recruitmentRes->assertJsonStructure(['funnel', 'total_openings', 'active_openings']);
    }
}
