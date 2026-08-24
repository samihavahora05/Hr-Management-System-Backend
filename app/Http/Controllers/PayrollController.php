<?php

namespace App\Http\Controllers;

use App\Models\Payroll;
use App\Models\User;
use App\Models\Organization;
use App\Models\AuditLog;
use App\Services\PayrollService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Carbon\Carbon;

class PayrollController extends Controller
{
    private function ensureTableExists()
    {
        if (!Schema::hasTable('payrolls')) {
            Schema::create('payrolls', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
                $table->foreignId('employee_id')->constrained('users')->onDelete('cascade');
                $table->string('pay_period_month');
                $table->integer('pay_period_year');
                $table->date('pay_date')->nullable();
                $table->string('payment_mode')->default('bank_transfer');
                $table->string('status')->default('generated');
                $table->json('earnings')->nullable();
                $table->json('deductions')->nullable();
                $table->decimal('total_earnings', 14, 2)->default(0.00);
                $table->decimal('total_deductions', 14, 2)->default(0.00);
                $table->decimal('net_salary', 14, 2)->default(0.00);
                $table->string('net_salary_words')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
                $table->timestamp('paid_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Admin list view with month, year, department, and status filters.
     */
    public function index(Request $request)
    {
        $this->ensureTableExists();

        $user = $request->user();
        if ($user->getCanonicalRole() !== 'admin') {
            return response()->json(['message' => 'Unauthorized: Only Administrator can access the organization payroll register.'], 403);
        }

        $orgId = $user->organization_id;
        $month = $request->query('month');
        $year = $request->query('year', Carbon::now()->year);
        $department = $request->query('department');
        $status = $request->query('status');
        $search = $request->query('search');

        $query = Payroll::where('organization_id', $orgId)
            ->with(['employee:id,name,email,employee_code,department,designation,joining_date,pan_number,bank_name,bank_account_no,status']);

        if ($month && $month !== 'all') {
            $query->where('pay_period_month', $month);
        }

        if ($year && $year !== 'all') {
            $query->where('pay_period_year', intval($year));
        }

        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }

        if ($department && $department !== 'all') {
            $query->whereHas('employee', function ($q) use ($department) {
                $q->where('department', $department);
            });
        }

        if ($search) {
            $query->whereHas('employee', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('employee_code', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $payrolls = $query->orderBy('id', 'desc')->get();

        // Calculate summary metrics for current selection
        $totalPayrollAmount = $payrolls->sum('net_salary');
        $paidCount = $payrolls->where('status', 'paid')->count();
        $generatedCount = $payrolls->where('status', 'generated')->count();
        $draftCount = $payrolls->where('status', 'draft')->count();

        // Get list of active departments for filter
        $departments = User::where('organization_id', $orgId)
            ->whereNotNull('department')
            ->select('department')
            ->distinct()
            ->pluck('department');

        return response()->json([
            'payrolls' => $payrolls,
            'metrics' => [
                'total_amount' => $totalPayrollAmount,
                'total_records' => $payrolls->count(),
                'paid_count' => $paidCount,
                'generated_count' => $generatedCount,
                'draft_count' => $draftCount,
            ],
            'departments' => $departments,
        ]);
    }

    /**
     * Create individual payroll record (Admin only).
     */
    public function store(Request $request)
    {
        $this->ensureTableExists();

        $user = $request->user();
        if ($user->getCanonicalRole() !== 'admin') {
            return response()->json(['message' => 'Unauthorized: Only Administrator can generate payroll.'], 403);
        }

        $request->validate([
            'employee_id' => 'required|exists:users,id',
            'pay_period_month' => 'required|string',
            'pay_period_year' => 'required|integer',
            'pay_date' => 'required|date',
            'payment_mode' => 'nullable|string|in:bank_transfer,cash,cheque',
            'earnings' => 'nullable|array',
            'deductions' => 'nullable|array',
            'notes' => 'nullable|string',
        ]);

        $orgId = $user->organization_id;
        $emp = User::where('organization_id', $orgId)->findOrFail($request->employee_id);

        $earnings = $request->earnings ?? PayrollService::getDefaultEarnings(floatval($emp->base_salary ?? 50000));
        $deductions = $request->deductions ?? PayrollService::getDefaultDeductions(floatval($emp->base_salary ?? 50000));

        $calc = PayrollService::calculateSummary($earnings, $deductions);

        $payroll = Payroll::updateOrCreate(
            [
                'organization_id' => $orgId,
                'employee_id' => $emp->id,
                'pay_period_month' => $request->pay_period_month,
                'pay_period_year' => $request->pay_period_year,
            ],
            [
                'pay_date' => $request->pay_date,
                'payment_mode' => $request->payment_mode ?? 'bank_transfer',
                'status' => 'generated',
                'earnings' => $earnings,
                'deductions' => $deductions,
                'total_earnings' => $calc['total_earnings'],
                'total_deductions' => $calc['total_deductions'],
                'net_salary' => $calc['net_salary'],
                'net_salary_words' => $calc['net_salary_words'],
                'created_by' => $user->id,
                'notes' => $request->notes,
            ]
        );

        AuditLog::create([
            'organization_id' => $orgId,
            'actor_id' => $user->id,
            'action' => 'create_payroll',
            'target_type' => Payroll::class,
            'target_id' => $payroll->id,
            'payload' => [
                'employee_id' => $emp->id,
                'net_salary' => $calc['net_salary'],
                'month_year' => "{$request->pay_period_month} {$request->pay_period_year}",
            ],
        ]);

        return response()->json([
            'message' => 'Payroll generated successfully',
            'payroll' => $payroll->load('employee'),
        ], 201);
    }

    /**
     * Bulk generate payroll for all active employees for given month and year.
     */
    public function bulkGenerate(Request $request)
    {
        $this->ensureTableExists();

        $user = $request->user();
        if ($user->getCanonicalRole() !== 'admin') {
            return response()->json(['message' => 'Unauthorized: Only Administrator can bulk generate payroll.'], 403);
        }

        $request->validate([
            'pay_period_month' => 'required|string',
            'pay_period_year' => 'required|integer',
            'pay_date' => 'required|date',
            'payment_mode' => 'nullable|string|in:bank_transfer,cash,cheque',
        ]);

        $orgId = $user->organization_id;
        $activeEmployees = User::where('organization_id', $orgId)
            ->where('status', 'active')
            ->get();

        $generatedCount = 0;
        $updatedCount = 0;

        foreach ($activeEmployees as $emp) {
            $existing = Payroll::where('organization_id', $orgId)
                ->where('employee_id', $emp->id)
                ->where('pay_period_month', $request->pay_period_month)
                ->where('pay_period_year', $request->pay_period_year)
                ->first();

            if ($existing && $existing->status === 'paid') {
                continue; // Do not overwrite paid records
            }

            $baseSalary = floatval($emp->base_salary ?? 60000);
            $earnings = PayrollService::getDefaultEarnings($baseSalary);
            $deductions = PayrollService::getDefaultDeductions($baseSalary);
            $calc = PayrollService::calculateSummary($earnings, $deductions);

            if ($existing) {
                $existing->update([
                    'pay_date' => $request->pay_date,
                    'payment_mode' => $request->payment_mode ?? 'bank_transfer',
                    'earnings' => $earnings,
                    'deductions' => $deductions,
                    'total_earnings' => $calc['total_earnings'],
                    'total_deductions' => $calc['total_deductions'],
                    'net_salary' => $calc['net_salary'],
                    'net_salary_words' => $calc['net_salary_words'],
                    'created_by' => $user->id,
                ]);
                $updatedCount++;
            } else {
                Payroll::create([
                    'organization_id' => $orgId,
                    'employee_id' => $emp->id,
                    'pay_period_month' => $request->pay_period_month,
                    'pay_period_year' => $request->pay_period_year,
                    'pay_date' => $request->pay_date,
                    'payment_mode' => $request->payment_mode ?? 'bank_transfer',
                    'status' => 'generated',
                    'earnings' => $earnings,
                    'deductions' => $deductions,
                    'total_earnings' => $calc['total_earnings'],
                    'total_deductions' => $calc['total_deductions'],
                    'net_salary' => $calc['net_salary'],
                    'net_salary_words' => $calc['net_salary_words'],
                    'created_by' => $user->id,
                ]);
                $generatedCount++;
            }
        }

        AuditLog::create([
            'organization_id' => $orgId,
            'actor_id' => $user->id,
            'action' => 'bulk_generate_payroll',
            'target_type' => Payroll::class,
            'target_id' => null,
            'payload' => [
                'generated' => $generatedCount,
                'updated' => $updatedCount,
                'month_year' => "{$request->pay_period_month} {$request->pay_period_year}",
            ],
        ]);

        return response()->json([
            'message' => "Bulk payroll completed: {$generatedCount} created, {$updatedCount} updated.",
            'generated_count' => $generatedCount,
            'updated_count' => $updatedCount,
        ]);
    }

    /**
     * Show single payroll record and formatted organization branding for PDF.
     */
    public function show(Request $request, $id)
    {
        $this->ensureTableExists();

        $user = $request->user();
        $role = $user->getCanonicalRole();

        // HR is strictly denied
        if ($role === 'hr') {
            return response()->json(['message' => 'Unauthorized: HR has no access to payroll.'], 403);
        }

        $payroll = Payroll::where('organization_id', $user->organization_id)
            ->where('id', $id)
            ->with(['employee', 'organization', 'creator'])
            ->first();

        if (!$payroll) {
            return response()->json(['message' => 'Payroll record not found'], 404);
        }

        // Non-admin can only see their own payslip
        if ($role !== 'admin' && (int)$payroll->employee_id !== (int)$user->id) {
            return response()->json(['message' => 'Unauthorized: You can only view your own payslips.'], 403);
        }

        return response()->json([
            'payroll' => $payroll,
        ]);
    }

    /**
     * Update payroll earnings and deductions (Admin only, locked if paid).
     */
    public function update(Request $request, $id)
    {
        $this->ensureTableExists();

        $user = $request->user();
        if ($user->getCanonicalRole() !== 'admin') {
            return response()->json(['message' => 'Unauthorized: Only Administrator can edit payroll.'], 403);
        }

        $payroll = Payroll::where('organization_id', $user->organization_id)
            ->where('id', $id)
            ->first();

        if (!$payroll) {
            return response()->json(['message' => 'Payroll record not found'], 404);
        }

        if ($payroll->status === 'paid') {
            return response()->json([
                'message' => 'Cannot modify a payroll record that has already been marked as Paid.'
            ], 422);
        }

        $request->validate([
            'earnings' => 'required|array',
            'deductions' => 'required|array',
            'pay_date' => 'nullable|date',
            'payment_mode' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $calc = PayrollService::calculateSummary($request->earnings, $request->deductions);

        $payroll->update([
            'earnings' => $request->earnings,
            'deductions' => $request->deductions,
            'total_earnings' => $calc['total_earnings'],
            'total_deductions' => $calc['total_deductions'],
            'net_salary' => $calc['net_salary'],
            'net_salary_words' => $calc['net_salary_words'],
            'pay_date' => $request->pay_date ?? $payroll->pay_date,
            'payment_mode' => $request->payment_mode ?? $payroll->payment_mode,
            'notes' => $request->notes ?? $payroll->notes,
        ]);

        AuditLog::create([
            'organization_id' => $user->organization_id,
            'actor_id' => $user->id,
            'action' => 'update_payroll',
            'target_type' => Payroll::class,
            'target_id' => $payroll->id,
            'payload' => ['net_salary' => $calc['net_salary']],
        ]);

        return response()->json([
            'message' => 'Payroll record updated successfully',
            'payroll' => $payroll->load('employee'),
        ]);
    }

    /**
     * Mark payroll as Paid, lock record, and trigger in-app notification to employee.
     */
    public function markPaid(Request $request, $id)
    {
        $this->ensureTableExists();

        $user = $request->user();
        if ($user->getCanonicalRole() !== 'admin') {
            return response()->json(['message' => 'Unauthorized: Only Administrator can mark payroll as paid.'], 403);
        }

        $payroll = Payroll::where('organization_id', $user->organization_id)
            ->where('id', $id)
            ->with('employee')
            ->first();

        if (!$payroll) {
            return response()->json(['message' => 'Payroll record not found'], 404);
        }

        if ($payroll->status === 'paid') {
            return response()->json(['message' => 'This payroll record is already marked as Paid.'], 400);
        }

        $payroll->status = 'paid';
        $payroll->paid_at = Carbon::now();
        $payroll->save();

        // Send employee notification
        NotificationService::create(
            $user->organization_id,
            $payroll->employee_id,
            'Salary Slip Available',
            "Your payslip for {$payroll->pay_period_month} {$payroll->pay_period_year} (Net Pay: ₹" . number_format($payroll->net_salary, 2) . ") has been processed and marked as Paid.",
            'success',
            '/employee/payroll'
        );

        AuditLog::create([
            'organization_id' => $user->organization_id,
            'actor_id' => $user->id,
            'action' => 'mark_payroll_paid',
            'target_type' => Payroll::class,
            'target_id' => $payroll->id,
            'payload' => ['employee_id' => $payroll->employee_id, 'net_salary' => $payroll->net_salary],
        ]);

        return response()->json([
            'message' => 'Payroll marked as Paid. Employee has been notified.',
            'payroll' => $payroll,
        ]);
    }

    /**
     * Read-only payslip history for the authenticated employee only.
     */
    public function employeePayslips(Request $request)
    {
        $this->ensureTableExists();

        $user = $request->user();

        $payslips = Payroll::where('organization_id', $user->organization_id)
            ->where('employee_id', $user->id)
            ->whereIn('status', ['generated', 'paid'])
            ->orderBy('pay_period_year', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        return response()->json([
            'payslips' => $payslips,
        ]);
    }

    /**
     * Get high-fidelity salary slip data for PDF rendering.
     */
    public function slipData(Request $request, $id)
    {
        $this->ensureTableExists();

        $user = $request->user();
        $role = $user->getCanonicalRole();

        if ($role === 'hr') {
            return response()->json(['message' => 'Unauthorized: HR has no access to payslips.'], 403);
        }

        $payroll = Payroll::where('organization_id', $user->organization_id)
            ->where('id', $id)
            ->with(['employee', 'organization'])
            ->first();

        if (!$payroll) {
            return response()->json(['message' => 'Payslip record not found'], 404);
        }

        if ($role !== 'admin' && (int)$payroll->employee_id !== (int)$user->id) {
            return response()->json(['message' => 'Unauthorized: You can only view your own payslips.'], 403);
        }

        $org = $payroll->organization;
        $settings = $org->settings ?? [];

        return response()->json([
            'slip' => [
                'id' => $payroll->id,
                'pay_period_month' => $payroll->pay_period_month,
                'pay_period_year' => $payroll->pay_period_year,
                'pay_date' => $payroll->pay_date ? Carbon::parse($payroll->pay_date)->format('d-M-Y') : Carbon::now()->format('d-M-Y'),
                'payment_mode' => ucwords(str_replace('_', ' ', $payroll->payment_mode ?? 'Bank Transfer')),
                'status' => $payroll->status,
                'earnings' => $payroll->earnings ?? [],
                'deductions' => $payroll->deductions ?? [],
                'total_earnings' => $payroll->total_earnings,
                'total_deductions' => $payroll->total_deductions,
                'net_salary' => $payroll->net_salary,
                'net_salary_words' => $payroll->net_salary_words,
                'employee' => [
                    'name' => $payroll->employee->name ?? 'Staff Member',
                    'employee_id' => $payroll->employee->employee_code ?? ('EMP-' . str_pad($payroll->employee->id, 4, '0', STR_PAD_LEFT)),
                    'designation' => $payroll->employee->designation ?? 'Software Engineer',
                    'department' => $payroll->employee->department ?? 'Engineering',
                    'joining_date' => $payroll->employee->joining_date ? Carbon::parse($payroll->employee->joining_date)->format('d-M-Y') : '01-Jan-2024',
                    'pan_number' => $payroll->employee->pan_number ?? 'ABCDE1234F',
                    'bank_name' => $payroll->employee->bank_name ?? 'HDFC Bank Ltd',
                    'bank_account_no' => $payroll->employee->bank_account_no ?? '50100234981290',
                ],
                'company' => [
                    'name' => 'BLUEBOXX DA PVT. LTD.',
                    'brand_title' => 'BLUEBOXX DA',
                    'brand_subtitle' => 'PVT. LTD.',
                    'tagline' => $settings['tagline'] ?? 'LEARNING TODAY, LEADING TOMORROW',
                    'address' => $settings['address'] ?? 'SF-02, India Bulls Mega Mall, Akota Road, near Jetalpur Bridge, Vadodara, Gujarat 390022.',
                    'website' => $settings['website'] ?? 'https://blueboxx.in/',
                    'email' => $settings['email'] ?? 'info.blueboxx@gmail.com',
                    'phone' => $settings['phone'] ?? '9023512853 | 6352524266',
                    'city' => $settings['city'] ?? 'VADODARA',
                    'state' => $settings['state'] ?? 'GUJARAT',
                ],
            ],
        ]);
    }
}
