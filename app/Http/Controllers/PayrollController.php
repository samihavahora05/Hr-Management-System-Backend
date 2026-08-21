<?php

namespace App\Http\Controllers;

use App\Models\PayrollRecord;
use App\Models\SalaryStructure;
use App\Models\User;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Carbon\Carbon;

class PayrollController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $roleName = $user->getCanonicalRole();

        $query = PayrollRecord::where('organization_id', $user->organization_id)
            ->with('user');

        // Employees and Managers see ONLY their own payroll records
        if (!in_array($roleName, ['admin', 'hr'])) {
            $query->where('user_id', $user->id);
        }

        if ($request->has('month_year') && $request->month_year != '') {
            $query->where('month_year', $request->month_year);
        }

        $payrolls = $query->orderBy('month_year', 'desc')->get();

        return response()->json(['payrolls' => $payrolls]);
    }

    public function generatePayroll(Request $request)
    {
        $actor = $request->user();
        $roleName = $actor->getCanonicalRole();

        if (!in_array($roleName, ['admin', 'hr'])) {
            return response()->json(['message' => 'Unauthorized: Only HR or Admin can process monthly payroll'], 403);
        }

        $request->validate([
            'month_year' => 'required|string',
        ]);

        $monthYear = $request->month_year;
        $employees = User::where('organization_id', $actor->organization_id)
            ->where('status', 'active')
            ->with('salaryStructure')
            ->get();

        $generatedCount = 0;

        foreach ($employees as $emp) {
            $structure = $emp->salaryStructure;
            if (!$structure) {
                $base = $emp->base_salary;
                $hra = round($base * 0.3, 2);
                $transport = round($base * 0.1, 2);
                $tax = round($base * 0.1, 2);
                $other = round($base * 0.05, 2);
                $net = $base + $hra + $transport - $tax - $other;
            } else {
                $base = $structure->base_salary;
                $hra = $structure->housing_allowance;
                $transport = $structure->transport_allowance;
                $tax = $structure->tax_deduction;
                $other = $structure->other_deductions;
                $net = $structure->net_salary;
            }

            PayrollRecord::updateOrCreate(
                [
                    'organization_id' => $actor->organization_id,
                    'user_id' => $emp->id,
                    'month_year' => $monthYear,
                ],
                [
                    'gross_salary' => $base + $hra + $transport,
                    'total_deductions' => $tax + $other,
                    'net_salary' => $net,
                    'status' => 'processed',
                    'payslip_url' => '/payslips/' . $monthYear . '-' . $emp->employee_code . '.pdf',
                ]
            );

            $generatedCount++;
        }

        AuditLog::create([
            'organization_id' => $actor->organization_id,
            'actor_id' => $actor->id,
            'action' => 'generate_payroll',
            'payload' => ['month_year' => $monthYear, 'count' => $generatedCount],
        ]);

        return response()->json([
            'message' => "Payroll run completed for {$monthYear}. Processed {$generatedCount} employee records."
        ]);
    }

    public function updateStatus(Request $request, $id)
    {
        $actor = $request->user();
        $roleName = $actor->getCanonicalRole();

        if (!in_array($roleName, ['admin', 'hr'])) {
            return response()->json(['message' => 'Unauthorized: Only HR or Admin can update payroll status'], 403);
        }

        $request->validate([
            'status' => 'required|in:draft,processed,paid',
        ]);

        $record = PayrollRecord::where('organization_id', $actor->organization_id)
            ->where('id', $id)
            ->first();

        if (!$record) {
            return response()->json(['message' => 'Payroll record not found'], 404);
        }

        $record->status = $request->status;
        if ($request->status === 'paid') {
            $record->paid_at = Carbon::now();
        }
        $record->save();

        return response()->json([
            'message' => 'Payroll status updated successfully',
            'payroll' => $record
        ]);
    }

    public function getPayslipDetail(Request $request, $id)
    {
        $user = $request->user();
        $roleName = $user->getCanonicalRole();

        $record = PayrollRecord::where('organization_id', $user->organization_id)
            ->where('id', $id)
            ->with(['user.organization', 'user.salaryStructure'])
            ->first();

        if (!$record) {
            return response()->json(['message' => 'Payslip record not found'], 404);
        }

        // Non-HR/Admin callers can ONLY view their own payslip
        if (!in_array($roleName, ['admin', 'hr']) && $record->user_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized: You can only view your own payslip'], 403);
        }

        return response()->json(['payslip' => $record]);
    }
}
