<?php

namespace App\Http\Controllers;

use App\Models\ExpenseClaim;
use App\Models\User;
use App\Models\AuditLog;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ExpenseController extends Controller
{
    /**
     * Check if user is authorized to access the expense claim / receipt.
     */
    private function canAccessExpense(User $user, ExpenseClaim $claim): bool
    {
        if ((int) $claim->organization_id !== (int) $user->organization_id) {
            return false;
        }

        if ((int) $claim->user_id === (int) $user->id) {
            return true;
        }

        $role = $user->getCanonicalRole();
        if (in_array($role, ['admin', 'hr'])) {
            return true;
        }

        if ($role === 'manager' || $role === 'team_leader') {
            $directReports = User::where('organization_id', $user->organization_id)
                ->where('manager_id', $user->id)
                ->pluck('id')
                ->toArray();
            return in_array($claim->user_id, $directReports);
        }

        return false;
    }

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
            'category' => 'required|string|max:100',
            'amount' => 'required|numeric|min:1',
            'claim_date' => 'required|date',
            'description' => 'required|string|max:1000',
            'receipt' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240',
            'receipt_url' => 'nullable|string',
        ]);

        $receiptPath = null;
        if ($request->hasFile('receipt')) {
            $uploaded = $request->file('receipt');
            $safeName = Str::uuid()->toString() . '.' . $uploaded->getClientOriginalExtension();
            $receiptPath = $uploaded->storeAs('receipts/' . $user->organization_id, $safeName, 'local');
        } elseif ($request->filled('receipt_url')) {
            $receiptPath = $request->receipt_url;
        }

        $claim = ExpenseClaim::create([
            'organization_id' => $user->organization_id,
            'user_id' => $user->id,
            'category' => $request->category,
            'amount' => $request->amount,
            'claim_date' => $request->claim_date,
            'description' => $request->description,
            'receipt_url' => $receiptPath,
            'status' => 'pending',
        ]);

        AuditLog::create([
            'organization_id' => $user->organization_id,
            'actor_id' => $user->id,
            'action' => 'create_expense_claim',
            'target_type' => ExpenseClaim::class,
            'target_id' => $claim->id,
            'payload' => ['category' => $claim->category, 'amount' => $claim->amount],
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

    public function downloadReceipt(Request $request, $id)
    {
        $user = $request->user();

        $claim = ExpenseClaim::where('organization_id', $user->organization_id)
            ->where('id', $id)
            ->first();

        if (!$claim || !$claim->receipt_url) {
            return response()->json(['message' => 'Receipt not found for this claim'], 404);
        }

        if (!$this->canAccessExpense($user, $claim)) {
            return response()->json(['message' => 'Unauthorized: You do not have access to this receipt'], 403);
        }

        if (!Storage::disk('local')->exists($claim->receipt_url)) {
            return response()->json(['message' => 'Physical receipt file not found on storage'], 404);
        }

        $ext = pathinfo($claim->receipt_url, PATHINFO_EXTENSION);
        return Storage::disk('local')->download($claim->receipt_url, "receipt_claim_{$claim->id}.{$ext}");
    }

    public function approve(Request $request, $id)
    {
        $approver = $request->user();
        $role = $approver->getCanonicalRole();

        if (!in_array($role, ['admin', 'hr'])) {
            return response()->json(['message' => 'Unauthorized: Only HR or Administrator can approve expense claims'], 403);
        }

        $claim = ExpenseClaim::where('organization_id', $approver->organization_id)->where('id', $id)->with('user')->first();
        if (!$claim) {
            return response()->json(['message' => 'Expense claim not found'], 404);
        }

        $claim->status = 'approved';
        $claim->approver_id = $approver->id;
        $claim->save();

        AuditLog::create([
            'organization_id' => $approver->organization_id,
            'actor_id' => $approver->id,
            'action' => 'approve_expense',
            'target_type' => ExpenseClaim::class,
            'target_id' => $claim->id,
            'payload' => ['amount' => $claim->amount],
        ]);

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
        $role = $approver->getCanonicalRole();

        if (!in_array($role, ['admin', 'hr'])) {
            return response()->json(['message' => 'Unauthorized: Only HR or Administrator can reject expense claims'], 403);
        }

        $claim = ExpenseClaim::where('organization_id', $approver->organization_id)->where('id', $id)->with('user')->first();
        if (!$claim) {
            return response()->json(['message' => 'Expense claim not found'], 404);
        }

        $claim->status = 'rejected';
        $claim->approver_id = $approver->id;
        $claim->rejection_reason = $request->rejection_reason ?? 'Declined by management';
        $claim->save();

        AuditLog::create([
            'organization_id' => $approver->organization_id,
            'actor_id' => $approver->id,
            'action' => 'reject_expense',
            'target_type' => ExpenseClaim::class,
            'target_id' => $claim->id,
            'payload' => ['reason' => $claim->rejection_reason],
        ]);

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
    }
}
