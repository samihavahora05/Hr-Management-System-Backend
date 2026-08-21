<?php

namespace App\Http\Controllers;

use App\Models\LeaveType;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Carbon\Carbon;

class LeaveController extends Controller
{
    public function getLeaveTypes(Request $request)
    {
        $user = $request->user();
        $orgId = $user->organization_id ?? 1;

        $types = LeaveType::where('organization_id', $orgId)->get();

        if ($types->isEmpty()) {
            $types = LeaveType::all();
        }

        if ($types->isEmpty()) {
            $defaults = [
                ['name' => 'Casual Leave (CL)', 'annual_quota' => 12, 'is_paid' => true],
                ['name' => 'Sick Leave (SL)', 'annual_quota' => 10, 'is_paid' => true],
                ['name' => 'Earned / Privilege Leave (PL)', 'annual_quota' => 15, 'is_paid' => true],
                ['name' => 'Maternity / Paternity Leave', 'annual_quota' => 30, 'is_paid' => true],
                ['name' => 'Compensatory Off (Comp-Off)', 'annual_quota' => 5, 'is_paid' => true],
                ['name' => 'Unpaid Leave (LOP)', 'annual_quota' => 0, 'is_paid' => false],
            ];
            foreach ($defaults as $d) {
                LeaveType::create([
                    'organization_id' => $orgId,
                    'name' => $d['name'],
                    'annual_quota' => $d['annual_quota'],
                    'is_paid' => $d['is_paid'],
                ]);
            }
            $types = LeaveType::where('organization_id', $orgId)->get();
        }

        return response()->json(['leave_types' => $types]);
    }

    public function getBalances(Request $request)
    {
        $user = $request->user();
        $roleName = strtolower($user->role->name ?? 'employee');
        $targetUserId = (int) $request->query('user_id', $user->id);

        if ($targetUserId !== $user->id) {
            if ($roleName !== 'admin') {
                return response()->json(['message' => 'Unauthorized: Cannot view leave balances of another employee'], 403);
            }

            $targetUser = User::where('organization_id', $user->organization_id)
                ->where('id', $targetUserId)
                ->first();

            if (!$targetUser) {
                return response()->json(['message' => 'Target employee not found'], 404);
            }
        }

        $balances = LeaveBalance::where('organization_id', $user->organization_id)
            ->where('user_id', $targetUserId)
            ->with('leaveType')
            ->get();

        if ($balances->isEmpty()) {
            $leaveTypes = LeaveType::where(function ($q) use ($user) {
                $q->where('organization_id', $user->organization_id)
                  ->orWhereNull('organization_id');
            })->get();

            if ($leaveTypes->isEmpty()) {
                $leaveTypes = LeaveType::all();
            }

            foreach ($leaveTypes as $lt) {
                $quota = $lt->max_days_per_year ?? $lt->annual_quota ?? 12;
                LeaveBalance::create([
                    'organization_id' => $user->organization_id,
                    'user_id' => $targetUserId,
                    'leave_type_id' => $lt->id,
                    'allocated' => $quota,
                    'used' => 0,
                    'remaining' => $quota,
                ]);
            }

            $balances = LeaveBalance::where('organization_id', $user->organization_id)
                ->where('user_id', $targetUserId)
                ->with('leaveType')
                ->get();
        }

        return response()->json(['balances' => $balances]);
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $roleName = strtolower($user->role->name ?? 'employee');

        $query = LeaveRequest::where('organization_id', $user->organization_id)
            ->with(['user', 'leaveType', 'approver']);

        // Non-admin roles (HR, Manager, Team Leader, Employee) ONLY see their own personal leave requests
        if ($roleName !== 'admin') {
            $query->where('user_id', $user->id);
        }
        // Only Admin can see organization-wide leave requests

        if ($request->has('status') && $request->status != '') {
            $query->where('status', $request->status);
        }

        $requests = $query->orderBy('created_at', 'desc')->get();

        return response()->json(['leave_requests' => $requests]);
    }

    public function store(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'leave_type_id' => 'required|exists:leave_types,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'reason' => 'required|string',
        ]);

        // Ensure leave_type belongs to caller organization
        $type = LeaveType::where('organization_id', $user->organization_id)
            ->where('id', $request->leave_type_id)
            ->first();

        if (!$type) {
            return response()->json(['message' => 'Invalid leave type for organization'], 404);
        }

        $start = Carbon::parse($request->start_date);
        $end = Carbon::parse($request->end_date);
        $daysCount = $start->diffInDays($end) + 1;

        // Check remaining balance
        $balance = LeaveBalance::where('organization_id', $user->organization_id)
            ->where('user_id', $user->id)
            ->where('leave_type_id', $request->leave_type_id)
            ->first();

        if ($balance && $balance->remaining < $daysCount) {
            return response()->json([
                'message' => "Insufficient leave balance. You have {$balance->remaining} days remaining, but requested {$daysCount} days."
            ], 422);
        }

        $leaveRequest = LeaveRequest::create([
            'organization_id' => $user->organization_id,
            'user_id' => $user->id,
            'leave_type_id' => $request->leave_type_id,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'days_count' => $daysCount,
            'reason' => $request->reason,
            'status' => 'pending',
        ]);

        NotificationService::notifyManagementChain(
            $user,
            'New Leave Request Submitted',
            "{$user->name} has requested leave from {$request->start_date} to {$request->end_date} ({$daysCount} days).",
            'warning',
            '/hr/leave'
        );

        return response()->json([
            'message' => 'Leave request submitted successfully',
            'leave_request' => $leaveRequest->load('leaveType')
        ], 201);
    }

    public function approve(Request $request, $id)
    {
        $approver = $request->user();
        $roleName = strtolower($approver->role->name ?? 'employee');

        if ($roleName !== 'admin') {
            return response()->json(['message' => 'Unauthorized: Only Administrator can approve leave requests'], 403);
        }

        $leaveRequest = LeaveRequest::where('organization_id', $approver->organization_id)
            ->where('id', $id)
            ->with('user')
            ->first();

        if (!$leaveRequest) {
            return response()->json(['message' => 'Leave request not found'], 404);
        }

        if ($leaveRequest->status !== 'pending') {
            return response()->json(['message' => 'This request has already been processed.'], 400);
        }

        $leaveRequest->status = 'approved';
        $leaveRequest->approver_id = $approver->id;
        $leaveRequest->save();

        // Deduct from leave balance
        $balance = LeaveBalance::where('organization_id', $approver->organization_id)
            ->where('user_id', $leaveRequest->user_id)
            ->where('leave_type_id', $leaveRequest->leave_type_id)
            ->first();

        if ($balance) {
            $balance->used += $leaveRequest->days_count;
            $balance->remaining = max(0, $balance->allocated - $balance->used);
            $balance->save();
        }

        // Auto-mark attendance as 'on_leave' for non-weekend dates in range
        $start = Carbon::parse($leaveRequest->start_date);
        $end = Carbon::parse($leaveRequest->end_date);
        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            if (!$date->isWeekend()) {
                $dateStr = $date->format('Y-m-d');
                $att = Attendance::where('organization_id', $approver->organization_id)
                    ->where('user_id', $leaveRequest->user_id)
                    ->whereDate('date', $dateStr)
                    ->first();

                if ($att) {
                    $att->status = 'on_leave';
                    $att->notes = 'Approved Leave Request #' . $leaveRequest->id;
                    $att->save();
                } else {
                    Attendance::create([
                        'organization_id' => $approver->organization_id,
                        'user_id' => $leaveRequest->user_id,
                        'date' => $dateStr,
                        'status' => 'on_leave',
                        'notes' => 'Approved Leave Request #' . $leaveRequest->id,
                    ]);
                }
            }
        }

        AuditLog::create([
            'organization_id' => $approver->organization_id,
            'actor_id' => $approver->id,
            'action' => 'approve_leave',
            'target_type' => LeaveRequest::class,
            'target_id' => $leaveRequest->id,
        ]);

        NotificationService::create(
            $approver->organization_id,
            $leaveRequest->user_id,
            'Leave Request Approved',
            "Your leave request from {$leaveRequest->start_date} to {$leaveRequest->end_date} was approved by {$approver->name}.",
            'success',
            '/employee/leave'
        );

        NotificationService::notifyManagementChain(
            $leaveRequest->user,
            'Leave Request Approved',
            "{$leaveRequest->user->name}'s leave request ({$leaveRequest->start_date} to {$leaveRequest->end_date}) was approved by {$approver->name}.",
            'success',
            '/hr/leave'
        );

        return response()->json([
            'message' => 'Leave request approved successfully',
            'leave_request' => $leaveRequest->load(['user', 'leaveType', 'approver'])
        ]);
    }

    public function reject(Request $request, $id)
    {
        $approver = $request->user();
        $roleName = strtolower($approver->role->name ?? 'employee');

        if ($roleName !== 'admin') {
            return response()->json(['message' => 'Unauthorized: Only Administrator can reject leave requests'], 403);
        }

        $leaveRequest = LeaveRequest::where('organization_id', $approver->organization_id)
            ->where('id', $id)
            ->with('user')
            ->first();

        if (!$leaveRequest) {
            return response()->json(['message' => 'Leave request not found'], 404);
        }

        $leaveRequest->status = 'rejected';
        $leaveRequest->approver_id = $approver->id;
        $leaveRequest->rejection_reason = $request->rejection_reason ?? 'Not approved by management';
        $leaveRequest->save();

        NotificationService::create(
            $approver->organization_id,
            $leaveRequest->user_id,
            'Leave Request Rejected',
            "Your leave request from {$leaveRequest->start_date} to {$leaveRequest->end_date} was rejected by {$approver->name}.",
            'error',
            '/employee/leave'
        );

        NotificationService::notifyManagementChain(
            $leaveRequest->user,
            'Leave Request Rejected',
            "{$leaveRequest->user->name}'s leave request ({$leaveRequest->start_date} to {$leaveRequest->end_date}) was rejected by {$approver->name}.",
            'error',
            '/hr/leave'
        );

        return response()->json([
            'message' => 'Leave request rejected',
            'leave_request' => $leaveRequest->load(['user', 'leaveType', 'approver'])
        ]);
    }
}
