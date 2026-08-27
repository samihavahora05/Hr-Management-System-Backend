<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Models\Payroll;
use App\Models\Notification;
use App\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPayrollTest extends TestCase
{
    use RefreshDatabase;

    protected $orgA;
    protected $orgB;
    protected $adminRole;
    protected $hrRole;
    protected $empRole;

    protected $adminA;
    protected $hrA;
    protected $empA;
    protected $empA2;

    protected $adminB;
    protected $empB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminRole = Role::create(['name' => 'admin', 'display_name' => 'Administrator']);
        $this->hrRole = Role::create(['name' => 'hr', 'display_name' => 'Human Resources']);
        $this->empRole = Role::create(['name' => 'employee', 'display_name' => 'Employee']);

        $this->orgA = Organization::create([
            'name' => 'Blueboxx DA Pvt. Ltd.',
            'code' => 'BBOX',
            'settings' => [
                'tagline' => 'Learning Today, Leading Tomorrow',
                'city' => 'Vadodara',
                'state' => 'Gujarat',
            ]
        ]);

        $this->orgB = Organization::create([
            'name' => 'Zenith Tech Ltd.',
            'code' => 'ZENITH'
        ]);

        $this->adminA = User::create([
            'organization_id' => $this->orgA->id,
            'role_id' => $this->adminRole->id,
            'name' => 'Admin User',
            'email' => 'admin@blueboxx.in',
            'password' => 'secret123',
            'status' => 'active',
            'remember_token' => 'tok_admin_a',
        ]);

        $this->hrA = User::create([
            'organization_id' => $this->orgA->id,
            'role_id' => $this->hrRole->id,
            'name' => 'HR Manager',
            'email' => 'hr@blueboxx.in',
            'password' => 'secret123',
            'status' => 'active',
            'remember_token' => 'tok_hr_a',
        ]);

        $this->empA = User::create([
            'organization_id' => $this->orgA->id,
            'role_id' => $this->empRole->id,
            'name' => 'Samiha Vahora',
            'email' => 'samiha@blueboxx.com',
            'employee_code' => 'EMP-1001',
            'department' => 'Engineering',
            'designation' => 'Full Stack Developer',
            'base_salary' => 75000.00,
            'password' => 'secret123',
            'status' => 'active',
            'remember_token' => 'tok_emp_a',
        ]);

        $this->empA2 = User::create([
            'organization_id' => $this->orgA->id,
            'role_id' => $this->empRole->id,
            'name' => 'Neha Sharma',
            'email' => 'neha@blueboxx.com',
            'employee_code' => 'EMP-1002',
            'department' => 'Design',
            'designation' => 'UI/UX Designer',
            'base_salary' => 65000.00,
            'password' => 'secret123',
            'status' => 'active',
            'remember_token' => 'tok_emp_a2',
        ]);

        $this->adminB = User::create([
            'organization_id' => $this->orgB->id,
            'role_id' => $this->adminRole->id,
            'name' => 'Beta Admin',
            'email' => 'admin@zenith.com',
            'password' => 'secret123',
            'status' => 'active',
            'remember_token' => 'tok_admin_b',
        ]);

        $this->empB = User::create([
            'organization_id' => $this->orgB->id,
            'role_id' => $this->empRole->id,
            'name' => 'Beta Emp',
            'email' => 'emp@zenith.com',
            'password' => 'secret123',
            'status' => 'active',
            'remember_token' => 'tok_emp_b',
        ]);
    }

    /** @test */
    public function admin_can_generate_single_and_bulk_payroll()
    {
        // 1. Single payroll generation with auto calculations
        $res = $this->withHeader('Authorization', 'Bearer tok_admin_a')
            ->postJson('/api/payroll', [
                'employee_id' => $this->empA->id,
                'pay_period_month' => 'May',
                'pay_period_year' => 2025,
                'pay_date' => '2025-05-31',
                'payment_mode' => 'bank_transfer',
                'earnings' => [
                    ['particulars' => 'Basic Salary', 'amount' => 37500],
                    ['particulars' => 'House Rent Allowance (HRA)', 'amount' => 15000],
                    ['particulars' => 'Special Allowance', 'amount' => 11250],
                ],
                'deductions' => [
                    ['particulars' => 'Provident Fund (PF)', 'amount' => 1800],
                    ['particulars' => 'Professional Tax (PT)', 'amount' => 200],
                ],
            ]);

        $res->assertStatus(201);
        $payroll = Payroll::first();
        $this->assertEquals(63750.00, $payroll->total_earnings);
        $this->assertEquals(2000.00, $payroll->total_deductions);
        $this->assertEquals(61750.00, $payroll->net_salary);
        $this->assertStringContainsString('Rupees Sixty One Thousand Seven Hundred Fifty Only', $payroll->net_salary_words);

        // 2. Bulk payroll generation for all active staff
        $bulkRes = $this->withHeader('Authorization', 'Bearer tok_admin_a')
            ->postJson('/api/payroll/bulk-generate', [
                'pay_period_month' => 'June',
                'pay_period_year' => 2025,
                'pay_date' => '2025-06-30',
                'payment_mode' => 'bank_transfer',
            ]);

        $bulkRes->assertStatus(200);
        // Active staff in Org A (adminA, hrA, empA, empA2) = 4
        $juneCount = Payroll::where('organization_id', $this->orgA->id)
            ->where('pay_period_month', 'June')
            ->count();
        $this->assertEquals(4, $juneCount);
    }

    /** @test */
    public function hr_cannot_access_any_payroll_endpoint()
    {
        // 1. HR list attempt -> 403
        $listRes = $this->withHeader('Authorization', 'Bearer tok_hr_a')
            ->getJson('/api/payroll');
        $listRes->assertStatus(403);

        // 2. HR create attempt -> 403
        $createRes = $this->withHeader('Authorization', 'Bearer tok_hr_a')
            ->postJson('/api/payroll', [
                'employee_id' => $this->empA->id,
                'pay_period_month' => 'May',
                'pay_period_year' => 2025,
                'pay_date' => '2025-05-31',
            ]);
        $createRes->assertStatus(403);

        // 3. HR bulk generate attempt -> 403
        $bulkRes = $this->withHeader('Authorization', 'Bearer tok_hr_a')
            ->postJson('/api/payroll/bulk-generate', [
                'pay_period_month' => 'May',
                'pay_period_year' => 2025,
                'pay_date' => '2025-05-31',
            ]);
        $bulkRes->assertStatus(403);
    }

    /** @test */
    public function employee_can_view_only_their_own_payslips()
    {
        $payrollA = Payroll::create([
            'organization_id' => $this->orgA->id,
            'employee_id' => $this->empA->id,
            'pay_period_month' => 'May',
            'pay_period_year' => 2025,
            'pay_date' => '2025-05-31',
            'status' => 'paid',
            'total_earnings' => 75000,
            'total_deductions' => 2000,
            'net_salary' => 73000,
            'net_salary_words' => 'Rupees Seventy Three Thousand Only',
        ]);

        $payrollA2 = Payroll::create([
            'organization_id' => $this->orgA->id,
            'employee_id' => $this->empA2->id,
            'pay_period_month' => 'May',
            'pay_period_year' => 2025,
            'pay_date' => '2025-05-31',
            'status' => 'paid',
            'total_earnings' => 65000,
            'total_deductions' => 2000,
            'net_salary' => 63000,
            'net_salary_words' => 'Rupees Sixty Three Thousand Only',
        ]);

        // Emp A gets their own payslips
        $empRes = $this->withHeader('Authorization', 'Bearer tok_emp_a')
            ->getJson('/api/employee/payslips');
        $empRes->assertStatus(200);
        $empRes->assertJsonCount(1, 'payslips');
        $empRes->assertJsonPath('payslips.0.id', $payrollA->id);

        // Emp A attempts to access Emp A2's salary slip data directly -> 403
        $unauthRes = $this->withHeader('Authorization', 'Bearer tok_emp_a')
            ->getJson("/api/payroll/{$payrollA2->id}/slip-data");
        $unauthRes->assertStatus(403);

        // Emp A accesses their own slip data -> 200 with structured company branding
        $ownRes = $this->withHeader('Authorization', 'Bearer tok_emp_a')
            ->getJson("/api/payroll/{$payrollA->id}/slip-data");
        $ownRes->assertStatus(200);
        $ownRes->assertJsonPath('slip.employee.name', 'Samiha Vahora');
    }

    /** @test */
    public function cross_tenant_isolation_on_payroll()
    {
        $payrollA = Payroll::create([
            'organization_id' => $this->orgA->id,
            'employee_id' => $this->empA->id,
            'pay_period_month' => 'May',
            'pay_period_year' => 2025,
            'pay_date' => '2025-05-31',
            'status' => 'generated',
            'total_earnings' => 75000,
            'total_deductions' => 2000,
            'net_salary' => 73000,
        ]);

        // Admin from Org B attempts to view Org A payroll -> 404
        $crossRes = $this->withHeader('Authorization', 'Bearer tok_admin_b')
            ->getJson("/api/payroll/{$payrollA->id}");
        $crossRes->assertStatus(404);
    }

    /** @test */
    public function marking_payroll_as_paid_locks_record_and_notifies_employee()
    {
        $payroll = Payroll::create([
            'organization_id' => $this->orgA->id,
            'employee_id' => $this->empA->id,
            'pay_period_month' => 'May',
            'pay_period_year' => 2025,
            'pay_date' => '2025-05-31',
            'status' => 'generated',
            'earnings' => [['particulars' => 'Basic', 'amount' => 50000]],
            'deductions' => [['particulars' => 'PF', 'amount' => 1800]],
            'total_earnings' => 50000,
            'total_deductions' => 1800,
            'net_salary' => 48200,
            'net_salary_words' => 'Rupees Forty Eight Thousand Two Hundred Only',
        ]);

        // 1. Mark as Paid
        $paidRes = $this->withHeader('Authorization', 'Bearer tok_admin_a')
            ->postJson("/api/payroll/{$payroll->id}/mark-paid");
        $paidRes->assertStatus(200);
        $this->assertEquals('paid', $payroll->fresh()->status);
        $this->assertNotNull($payroll->fresh()->paid_at);

        // 2. Notification is generated for Employee
        $notif = Notification::where('user_id', $this->empA->id)->first();
        $this->assertNotNull($notif);
        $this->assertEquals('Salary Slip Available', $notif->title);

        // 3. Modifying a paid record is blocked
        $editRes = $this->withHeader('Authorization', 'Bearer tok_admin_a')
            ->putJson("/api/payroll/{$payroll->id}", [
                'earnings' => [['particulars' => 'Basic', 'amount' => 60000]],
                'deductions' => [['particulars' => 'PF', 'amount' => 1800]],
            ]);
        $editRes->assertStatus(422);
    }
}
