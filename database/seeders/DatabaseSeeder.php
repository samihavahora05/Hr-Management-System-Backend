<?php

namespace Database\Seeders;
use App\Models\Organization;
use App\Models\Branch;
use App\Models\Location;
use App\Models\Shift;
use App\Models\Team;
use App\Models\JobRole;
use App\Models\Role;
use App\Models\Permission;
use App\Models\User;
use App\Models\LeaveType;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\Attendance;
use App\Models\Announcement;
use App\Models\OnboardingChecklist;
use App\Models\EmployeeRiskScore;
use App\Models\EmployeeDocument;
use App\Models\JobOpening;
use App\Models\Candidate;
use App\Models\PerformanceCycle;
use App\Models\Goal;
use App\Models\PerformanceReview;
use App\Models\ExpenseClaim;
use App\Models\LoanRequest;
use App\Models\Timesheet;
use App\Models\Asset;
use App\Models\HelpdeskTicket;
use App\Models\Notification;
use App\Models\Task;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;

class DatabaseSeeder extends Seeder
{
    /**
     * Run the database seeders safely and idempotently.
     * Running this seeder multiple times will never crash, create duplicate records, or corrupt data.
     */
    public function run(): void
    {
        // 1. Ensure Standard Roles exist
        Role::ensureStandardRoles();
        $adminRole = Role::getByName('admin');
        $hrRole = Role::getByName('hr');
        $managerRole = Role::getByName('manager');
        $teamLeaderRole = Role::getByName('team_leader');
        $empRole = Role::getByName('employee');

        // 2. Organization (Idempotent)
        $org = Organization::firstOrCreate(
            ['code' => 'BLUEBOXX'],
            [
                'name' => 'BLUEBOXX HRMS Enterprise Pvt Ltd',
                'settings' => [
                    'timezone' => 'Asia/Kolkata',
                    'currency' => 'INR',
                    'fiscal_year_start' => '04-01',
                    'logo_url' => '/images/logoblue.png',
                    'icon_logo_url' => '/images/Boxxlogo.png',
                    'office_location' => [
                        'enabled' => true,
                        'name' => 'Main Office Headquarters',
                        'latitude' => 22.3039,
                        'longitude' => 73.1783,
                        'radius_meters' => 500,
                        'address' => 'SF 02, INDIA BULLS MEGA MALL, Dinesh Mill Rd, near Swami Vivekananda Railway Over Bridge, Anand Nagar, Akota, Vadodara, Gujarat 390022',
                    ],
                    'holiday_calendar' => [
                        ['date' => '2026-01-26', 'title' => 'Republic Day'],
                        ['date' => '2026-08-15', 'title' => 'Independence Day'],
                        ['date' => '2026-10-02', 'title' => 'Gandhi Jayanti'],
                        ['date' => '2026-10-20', 'title' => 'Diwali'],
                        ['date' => '2026-12-25', 'title' => 'Christmas'],
                    ]
                ]
            ]
        );

        // 3. Branches, Locations & Shifts
        $hqBranch = Branch::firstOrCreate(
            ['organization_id' => $org->id, 'code' => 'HQ-BDQ'],
            [
                'name' => 'Headquarters (Vadodara)',
                'address' => 'SF 02, INDIA BULLS MEGA MALL, Dinesh Mill Rd, near Swami Vivekananda Railway Over Bridge, Anand Nagar, Akota, Vadodara, Gujarat 390022',
                'status' => 'active',
            ]
        );

        $vadodaraLocation = Location::firstOrCreate(
            ['organization_id' => $org->id, 'name' => 'India Bulls Mega Mall'],
            [
                'city' => 'Vadodara',
                'state' => 'Gujarat',
                'country' => 'India',
                'postal_code' => '390022',
            ]
        );

        $generalShift = Shift::firstOrCreate(
            ['organization_id' => $org->id, 'name' => 'General Day Shift (10:00 AM - 06:00 PM)'],
            [
                'start_time' => '10:00:00',
                'end_time' => '18:00:00',
                'grace_period_minutes' => 15,
                'work_days' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'],
            ]
        );

        Shift::firstOrCreate(
            ['organization_id' => $org->id, 'name' => 'US Evening Shift (02:00 PM - 11:00 PM)'],
            [
                'start_time' => '14:00:00',
                'end_time' => '23:00:00',
                'grace_period_minutes' => 15,
                'work_days' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'],
            ]
        );

        Shift::firstOrCreate(
            ['organization_id' => $org->id, 'name' => 'Night Support Shift (10:00 PM - 07:00 AM)'],
            [
                'start_time' => '22:00:00',
                'end_time' => '07:00:00',
                'grace_period_minutes' => 15,
                'work_days' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'],
            ]
        );

        // 4. Default Passwords
        $defaultPassword = Hash::make('Blueboxx@2026');

        // 5. Master Users (Idempotent upsert by email or employee_code)
        $upsertUser = function (string $email, string $employeeCode, array $attributes) use ($defaultPassword): User {
            $user = User::where('email', $email)->orWhere('employee_code', $employeeCode)->first();
            $data = array_merge([
                'email' => $email,
                'employee_code' => $employeeCode,
                'password' => $defaultPassword,
            ], $attributes);

            if ($user) {
                $user->update($data);
                return $user;
            }

            return User::create($data);
        };

        $admin = $upsertUser('admin@blueboxx.com', 'EMP001', [
            'organization_id' => $org->id,
            'role_id' => $adminRole->id,
            'branch_id' => $hqBranch->id,
            'location_id' => $vadodaraLocation->id,
            'shift_id' => $generalShift->id,
            'name' => 'Admin User',
            'department' => 'Executive',
            'designation' => 'Director of Operations',
            'joining_date' => '2022-01-10',
            'status' => 'active',
            'phone' => '+91 98765 43210',
            'base_salary' => 150000.00,
            'gender' => 'Male',
            'work_mode' => 'office',
            'probation_status' => 'confirmed',
            'pan' => 'ABCDE1234F',
            'tax_regime' => 'new',
        ]);

        $hr = $upsertUser('hr@blueboxx.com', 'EMP002', [
            'organization_id' => $org->id,
            'role_id' => $hrRole->id,
            'branch_id' => $hqBranch->id,
            'location_id' => $vadodaraLocation->id,
            'shift_id' => $generalShift->id,
            'name' => 'Neha Sharma',
            'department' => 'Human Resources',
            'designation' => 'HR Operations Lead',
            'joining_date' => '2023-03-15',
            'status' => 'active',
            'phone' => '+91 98765 43211',
            'base_salary' => 85000.00,
            'manager_id' => $admin->id,
            'gender' => 'Female',
            'work_mode' => 'office',
            'probation_status' => 'confirmed',
            'pan' => 'FGHIJ5678K',
            'tax_regime' => 'new',
        ]);

        $manager = $upsertUser('manager@blueboxx.com', 'EMP003', [
            'organization_id' => $org->id,
            'role_id' => $managerRole->id,
            'branch_id' => $hqBranch->id,
            'location_id' => $vadodaraLocation->id,
            'shift_id' => $generalShift->id,
            'name' => 'Rajesh Kumar',
            'department' => 'Engineering',
            'designation' => 'Company Engineering Manager',
            'joining_date' => '2023-06-01',
            'status' => 'active',
            'phone' => '+91 98765 43212',
            'base_salary' => 120000.00,
            'manager_id' => $admin->id,
            'gender' => 'Male',
            'work_mode' => 'hybrid',
            'probation_status' => 'confirmed',
            'pan' => 'KLMNO9012P',
            'tax_regime' => 'new',
        ]);

        $teamLeader = $upsertUser('teamlead@blueboxx.com', 'EMP007', [
            'organization_id' => $org->id,
            'role_id' => $teamLeaderRole->id,
            'branch_id' => $hqBranch->id,
            'location_id' => $vadodaraLocation->id,
            'shift_id' => $generalShift->id,
            'name' => 'Vikram Singh',
            'department' => 'Engineering',
            'designation' => 'Frontend Team Leader',
            'joining_date' => '2023-09-15',
            'status' => 'active',
            'phone' => '+91 98765 43216',
            'base_salary' => 95000.00,
            'manager_id' => $manager->id,
            'gender' => 'Male',
            'work_mode' => 'office',
            'probation_status' => 'confirmed',
            'pan' => 'QRSTU3456V',
            'tax_regime' => 'new',
        ]);

        $emp1 = $upsertUser('employee@blueboxx.com', 'EMP004', [
            'organization_id' => $org->id,
            'role_id' => $empRole->id,
            'branch_id' => $hqBranch->id,
            'location_id' => $vadodaraLocation->id,
            'shift_id' => $generalShift->id,
            'name' => 'Aarav Patel',
            'department' => 'Engineering',
            'designation' => 'Senior Frontend Developer',
            'joining_date' => '2024-02-01',
            'status' => 'active',
            'phone' => '+91 98765 43213',
            'base_salary' => 75000.00,
            'manager_id' => $teamLeader->id,
            'gender' => 'Male',
            'work_mode' => 'office',
            'probation_status' => 'confirmed',
            'pan' => 'VWXYZ7890A',
            'tax_regime' => 'new',
        ]);

        $emp2 = $upsertUser('priya@blueboxx.com', 'EMP005', [
            'organization_id' => $org->id,
            'role_id' => $empRole->id,
            'branch_id' => $hqBranch->id,
            'location_id' => $vadodaraLocation->id,
            'shift_id' => $generalShift->id,
            'name' => 'Priya Verma',
            'department' => 'Engineering',
            'designation' => 'UI/UX Designer',
            'joining_date' => '2024-05-10',
            'status' => 'active',
            'phone' => '+91 98765 43214',
            'base_salary' => 70000.00,
            'manager_id' => $teamLeader->id,
            'gender' => 'Female',
            'work_mode' => 'hybrid',
            'probation_status' => 'confirmed',
            'pan' => 'BCDEF1234G',
            'tax_regime' => 'new',
        ]);

        $emp3 = $upsertUser('rohan@blueboxx.com', 'EMP006', [
            'organization_id' => $org->id,
            'role_id' => $empRole->id,
            'branch_id' => $hqBranch->id,
            'location_id' => $vadodaraLocation->id,
            'shift_id' => $generalShift->id,
            'name' => 'Rohan Mehta',
            'department' => 'Human Resources',
            'designation' => 'HR Operations Assistant',
            'joining_date' => '2024-08-01',
            'status' => 'active',
            'phone' => '+91 98765 43215',
            'base_salary' => 65000.00,
            'manager_id' => $hr->id,
            'gender' => 'Male',
            'work_mode' => 'office',
            'probation_status' => 'probation',
            'pan' => 'HIJKL5678M',
            'tax_regime' => 'new',
        ]);

        $allUsers = [$admin, $hr, $manager, $teamLeader, $emp1, $emp2, $emp3];

        // 6. Teams Setup
        Team::firstOrCreate(
            ['organization_id' => $org->id, 'code' => 'FE-UI'],
            [
                'leader_id' => $teamLeader->id,
                'name' => 'Frontend UI Team',
                'description' => 'Responsible for Web App Frontend components and UX',
            ]
        );

        // 7. Leave Types & Balances
        $casual = LeaveType::firstOrCreate(
            ['organization_id' => $org->id, 'name' => 'Casual Leave'],
            ['annual_quota' => 12, 'is_paid' => true]
        );
        $sick = LeaveType::firstOrCreate(
            ['organization_id' => $org->id, 'name' => 'Sick Leave'],
            ['annual_quota' => 10, 'is_paid' => true]
        );
        $earned = LeaveType::firstOrCreate(
            ['organization_id' => $org->id, 'name' => 'Earned Leave'],
            ['annual_quota' => 15, 'is_paid' => true]
        );

        foreach ($allUsers as $u) {
            LeaveBalance::firstOrCreate(
                ['organization_id' => $org->id, 'user_id' => $u->id, 'leave_type_id' => $casual->id],
                ['allocated' => 12, 'used' => 2, 'remaining' => 10]
            );
            LeaveBalance::firstOrCreate(
                ['organization_id' => $org->id, 'user_id' => $u->id, 'leave_type_id' => $sick->id],
                ['allocated' => 10, 'used' => 1, 'remaining' => 9]
            );
            LeaveBalance::firstOrCreate(
                ['organization_id' => $org->id, 'user_id' => $u->id, 'leave_type_id' => $earned->id],
                ['allocated' => 15, 'used' => 3, 'remaining' => 12]
            );

            // Employee Documents
            EmployeeDocument::firstOrCreate(
                ['organization_id' => $org->id, 'user_id' => $u->id, 'title' => 'Employment Contract'],
                [
                    'type' => 'contract',
                    'file_url' => '/documents/contract_' . strtolower($u->employee_code ?? 'emp') . '.pdf',
                ]
            );

            // In-app Notifications
            Notification::firstOrCreate(
                ['organization_id' => $org->id, 'user_id' => $u->id, 'title' => 'Welcome to BLUEBOXX HRMS'],
                [
                    'message' => 'Your employee account is fully configured and active.',
                    'type' => 'info',
                ]
            );
        }

        // 8. Attendance (last 10 days)
        $today = Carbon::today();
        for ($i = 10; $i >= 1; $i--) {
            $date = $today->copy()->subDays($i);
            if ($date->isWeekend()) continue;

            foreach ($allUsers as $u) {
                $status = 'present';
                $checkIn = '09:00:00';
                $checkOut = '18:00:00';

                if ($u->id === $emp3->id && $i % 2 === 0) {
                    $status = 'late';
                    $checkIn = '10:15:00';
                }

                Attendance::firstOrCreate(
                    [
                        'organization_id' => $org->id,
                        'user_id' => $u->id,
                        'date' => $date->format('Y-m-d'),
                    ],
                    [
                        'check_in' => $checkIn,
                        'check_out' => $checkOut,
                        'status' => $status,
                    ]
                );
            }
        }

        // 9. Recruitment Job Openings & Candidates
        $opening = JobOpening::firstOrCreate(
            ['organization_id' => $org->id, 'title' => 'Senior Full-Stack Engineer'],
            [
                'department' => 'Engineering',
                'location' => 'Vadodara',
                'type' => 'full_time',
                'experience_level' => '3-5 Years',
                'description' => 'We are seeking an experienced Full Stack Developer to build enterprise web platforms.',
                'status' => 'active',
                'published_at' => now(),
            ]
        );

        Candidate::firstOrCreate(
            ['organization_id' => $org->id, 'email' => 'siddharth.rao@example.com'],
            [
                'job_opening_id' => $opening->id,
                'name' => 'Siddharth Rao',
                'phone' => '+91 99887 76655',
                'resume_url' => '/resumes/Siddharth_Resume.pdf',
                'stage' => 'interview',
                'rating' => 4,
                'notes' => 'Strong system design skills and Next.js / Laravel backend experience.',
            ]
        );

        // 10. Performance Cycle & Goals
        $cycle = PerformanceCycle::firstOrCreate(
            ['organization_id' => $org->id, 'title' => 'Q3 2026 Performance Review Cycle'],
            [
                'start_date' => '2026-07-01',
                'end_date' => '2026-09-30',
                'status' => 'active',
            ]
        );

        Goal::firstOrCreate(
            ['organization_id' => $org->id, 'title' => 'Deliver HRMS Core Module Refactoring'],
            [
                'cycle_id' => $cycle->id,
                'user_id' => $emp1->id,
                'description' => 'Complete single master employee record integration and statutory payroll engine',
                'target_value' => 100,
                'current_value' => 85,
                'weightage' => 40,
                'status' => 'in_progress',
            ]
        );

        // 11. Sample Expense Claim, Asset, and Helpdesk Ticket
        ExpenseClaim::firstOrCreate(
            ['organization_id' => $org->id, 'user_id' => $emp1->id, 'category' => 'Internet Allowance'],
            [
                'amount' => 1500.00,
                'claim_date' => now()->toDateString(),
                'description' => 'Monthly high-speed fiber internet reimbursement',
                'status' => 'pending',
            ]
        );

        Asset::firstOrCreate(
            ['organization_id' => $org->id, 'asset_code' => 'AST-MBP-001'],
            [
                'name' => 'MacBook Pro 16" M3',
                'category' => 'Laptop',
                'serial_number' => 'C02GX001MD6M',
                'assigned_to' => $emp1->id,
                'assigned_at' => '2024-02-01',
                'condition' => 'good',
                'status' => 'assigned',
            ]
        );

        HelpdeskTicket::firstOrCreate(
            ['organization_id' => $org->id, 'ticket_number' => 'TICK-0001'],
            [
                'requester_id' => $emp2->id,
                'category' => 'Tax Information Query',
                'priority' => 'medium',
                'subject' => 'Form 16 Tax Regime Selection',
                'description' => 'Kindly confirm if my salary structure is set to the New Tax Regime for FY 2026-27.',
                'status' => 'open',
            ]
        );

        Announcement::firstOrCreate(
            ['organization_id' => $org->id, 'title' => 'Production HRMS Platform Live'],
            [
                'author_id' => $admin->id,
                'content' => 'The enterprise HR management platform is live. Employees can access attendance, leaves, payroll, expenses, and HR requests.',
                'target_role' => 'all',
                'is_pinned' => true,
            ]
        );

        // 12. Sample Work Tasks
        Task::firstOrCreate(
            ['organization_id' => $org->id, 'title' => 'Deliver HRMS Core Module Integration'],
            [
                'assigner_id' => $admin->id,
                'assigned_to' => $emp1->id,
                'assigned_by_role' => 'admin',
                'assigned_to_role' => 'employee',
                'description' => 'Complete single master employee record integration, attendance sync, and role permissions.',
                'category' => 'project',
                'priority' => 'high',
                'status' => 'in_progress',
                'progress_percentage' => 75,
                'start_date' => now()->subDays(5)->toDateString(),
                'due_date' => now()->addDays(5)->toDateString(),
                'subtasks' => [
                    ['id' => '1', 'title' => 'Master User Sync', 'completed' => true],
                    ['id' => '2', 'title' => 'Role Scoping Audit', 'completed' => true],
                    ['id' => '3', 'title' => 'Final QA Testing', 'completed' => false],
                ],
                'notes' => 'High priority operational task for Q3 milestone.',
            ]
        );

        Task::firstOrCreate(
            ['organization_id' => $org->id, 'title' => 'Quarterly HR Performance & Policy Review'],
            [
                'assigner_id' => $admin->id,
                'assigned_to' => $hr->id,
                'assigned_by_role' => 'admin',
                'assigned_to_role' => 'hr',
                'description' => 'Review Q3 employee goals, attendance anomalies, and team appraisal cycles.',
                'category' => 'review',
                'priority' => 'urgent',
                'status' => 'todo',
                'progress_percentage' => 0,
                'start_date' => now()->toDateString(),
                'due_date' => now()->addDays(3)->toDateString(),
                'subtasks' => [
                    ['id' => '1', 'title' => 'Compile Attrition Risk Summary', 'completed' => false],
                    ['id' => '2', 'title' => 'Verify Department Manager Feedback', 'completed' => false],
                ],
                'notes' => 'Requires executive board sign-off.',
            ],
        );

        Task::firstOrCreate(
            ['organization_id' => $org->id, 'title' => 'Update Employee Tax Declarations'],
            [
                'assigner_id' => $hr->id,
                'assigned_to' => $emp2->id,
                'assigned_by_role' => 'hr',
                'assigned_to_role' => 'employee',
                'description' => 'Verify investment proofs, PAN details, and tax regime preferences for FY 2026-27.',
                'category' => 'compliance',
                'priority' => 'medium',
                'status' => 'completed',
                'progress_percentage' => 100,
                'start_date' => now()->subDays(10)->toDateString(),
                'due_date' => now()->subDays(2)->toDateString(),
                'subtasks' => [
                    ['id' => '1', 'title' => 'Upload Rent Receipts', 'completed' => true],
                    ['id' => '2', 'title' => 'Verify 80C Investment Proofs', 'completed' => true],
                ],
                'notes' => 'Verified and approved by HR Compliance team.',
            ]
        );
    }
}
