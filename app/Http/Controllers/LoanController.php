<?php

namespace App\Http\Controllers;

use App\Models\LoanRequest;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\Request;

class LoanController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $role = $user->getCanonicalRole();

        $query = LoanRequest::where('organization_id', $user->organization_id)->with(['user', 'approver']);

        if (!in_array($role, ['admin', 'hr'])) {
            $query->where('user_id', $user->id);
        }

        $loans = $query->orderBy('created_at', 'desc')->get();
        return response()->json(['loans' => $loans]);
    }

    public function store(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'amount' => 'required|numeric|min:1000',
            'tenure_months' => 'required|integer|min:1|max:36',
            'reason' => 'required|string',
        ]);

        $monthlyInstallment = round($request->amount / $request->tenure_months, 2);

        $loan = LoanRequest::create([
            'organization_id' => $user->organization_id,
            'user_id' => $user->id,
            'amount' => $request->amount,
            'tenure_months' => $request->tenure_months,
            'monthly_installment' => $monthlyInstallment,
            'reason' => $request->reason,
            'status' => 'pending',
            'outstanding_balance' => $request->amount,
        ]);

        return response()->json(['message' => 'Loan request submitted successfully', 'loan' => $loan], 201);
    }

    public function approve(Request $request, $id)
    {
        $approver = $request->user();
        if (!in_array($approver->getCanonicalRole(), ['admin', 'hr'])) {
            return response()->json(['message' => 'Unauthorized: Only HR or Admin can approve loan requests'], 403);
        }

        $loan = LoanRequest::where('organization_id', $approver->organization_id)->where('id', $id)->first();
        if (!$loan) {
            return response()->json(['message' => 'Loan request not found'], 404);
        }

        $loan->status = 'approved';
        $loan->approver_id = $approver->id;
        $loan->repayment_started_at = now();
        $loan->save();

        NotificationService::create(
            $approver->organization_id,
            $loan->user_id,
            'Loan Request Approved',
            "Your loan request of ₹{$loan->amount} has been approved.",
            'success'
        );

        return response()->json(['message' => 'Loan request approved successfully', 'loan' => $loan]);
    }
}
