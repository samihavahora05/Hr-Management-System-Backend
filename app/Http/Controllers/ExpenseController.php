<?php

namespace App\Http\Controllers;

use App\Models\ExpenseClaim;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\Request;

class ExpenseController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $role = $user->getCanonicalRole();

        $query = ExpenseClaim::where('organization_id', $user->organization_id)->with(['user', 'approver']);

        if ($role === 'employee') {
            $query->where('user_id', $user->id);
        } elseif (in_array($role, ['manager', 'team_leader'])) {
            $teamEmpIds = User::where('organization_id', $user->organization_id)->where('manager_id', $user->id)->pluck('id')->toArray();
            $teamEmpIds[] = $user->id;
            $query->whereIn('user_id', $teamEmpIds);
        }

        if ($request->has('status') && $request->status != '') {
            $query->where('status', $request->status);
        }

        $claims = $query->orderBy('created_at', 'desc')->get();
        return response()->json(['claims' => $claims]);
    }

    public function store(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'category' => 'required|string',
            'amount' => 'required|numeric|min:1',
            'claim_date' => 'required|date',
            'description' => 'required|string',
            'receipt_url' => 'nullable|string',
        ]);

        $claim = ExpenseClaim::create([
            'organization_id' => $user->organization_id,
            'user_id' => $user->id,
            'category' => $request->category,
            'amount' => $request->amount,
            'claim_date' => $request->claim_date,
            'description' => $request->description,
            'receipt_url' => $request->receipt_url ?? '/uploads/receipt_sample.pdf',
            'status' => 'pending',
        ]);

        NotificationService::notifyManagementChain(
            $user,
            'New Expense Claim Submitted',
            "{$user->name} submitted an expense claim of ₹{$request->amount} for {$request->category}.",
            'info',
            '/expenses'
        );

        return response()->json(['message' => 'Expense claim submitted successfully', 'claim' => $claim], 201);
    }

    public function approve(Request $request, $id)
    {
        $approver = $request->user();
        if (!in_array($approver->getCanonicalRole(), ['admin', 'hr', 'manager', 'company_manager', 'team_leader'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $claim = ExpenseClaim::where('organization_id', $approver->organization_id)->where('id', $id)->with('user')->first();
        if (!$claim) {
            return response()->json(['message' => 'Expense claim not found'], 404);
        }

        $claim->status = 'approved';
        $claim->approver_id = $approver->id;
        $claim->save();

        NotificationService::create(
            $approver->organization_id,
            $claim->user_id,
            'Expense Claim Approved',
            "Your expense claim of ₹{$claim->amount} has been approved by {$approver->name}.",
            'success',
            '/expenses'
        );

        if ($claim->user) {
            NotificationService::notifyManagementChain(
                $claim->user,
                'Expense Claim Approved',
                "{$claim->user->name}'s expense claim of ₹{$claim->amount} was approved by {$approver->name}.",
                'success',
                '/expenses'
            );
        }

        return response()->json(['message' => 'Expense claim approved successfully', 'claim' => $claim->load(['user', 'approver'])]);
    }

    public function reject(Request $request, $id)
    {
        $approver = $request->user();
        if (!in_array($approver->getCanonicalRole(), ['admin', 'hr', 'manager', 'company_manager', 'team_leader'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $claim = ExpenseClaim::where('organization_id', $approver->organization_id)->where('id', $id)->with('user')->first();
        if (!$claim) {
            return response()->json(['message' => 'Expense claim not found'], 404);
        }

        $claim->status = 'rejected';
        $claim->approver_id = $approver->id;
        $claim->rejection_reason = $request->rejection_reason ?? 'Declined by management';
        $claim->save();

        NotificationService::create(
            $approver->organization_id,
            $claim->user_id,
            'Expense Claim Rejected',
            "Your expense claim of ₹{$claim->amount} was rejected by {$approver->name}.",
            'warning',
            '/expenses'
        );

        if ($claim->user) {
            NotificationService::notifyManagementChain(
                $claim->user,
                'Expense Claim Rejected',
                "{$claim->user->name}'s expense claim of ₹{$claim->amount} was rejected by {$approver->name}.",
                'warning',
                '/expenses'
            );
        }

        return response()->json(['message' => 'Expense claim rejected', 'claim' => $claim->load(['user', 'approver'])]);

        return response()->json(['message' => 'Expense claim rejected', 'claim' => $claim]);
    }
}
