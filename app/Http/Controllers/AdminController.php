<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Role;
use App\Models\Organization;
use App\Models\Attendance;
use App\Models\LeaveRequest;
use App\Models\JobOpening;
use App\Models\Candidate;
use App\Models\ExpenseClaim;
use App\Models\AuditLog;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;

class AdminController extends Controller
{
    /**
     * Admin Command Center Organization Overview Stats
     */
    public function stats(Request $request)
    {
        $admin = $request->user();
        $orgId = $admin->organization_id;

        // Employees metrics
        $totalUsers = User::where('organization_id', $orgId)->count();
        $activeUsers = User::where('organization_id', $orgId)->where('status', 'active')->count();
        $onLeaveUsers = User::where('organization_id', $orgId)->where('status', 'on_leave')->count();
        $inactiveUsers = User::where('organization_id', $orgId)->where('status', 'inactive')->count();

        // Structure counts
        $departmentsCount = User::where('organization_id', $orgId)->whereNotNull('department')->distinct('department')->count('department');
        $managerRoleIds = Role::whereIn('name', ['manager', 'company_manager'])->pluck('id');
        $tlRoleId = Role::where('name', 'team_leader')->value('id');
        $managersCount = User::where('organization_id', $orgId)->whereIn('role_id', $managerRoleIds)->count();
        $teamLeadersCount = User::where('organization_id', $orgId)->where('role_id', $tlRoleId)->count();

        // Today's Attendance
        $today = Carbon::today()->toDateString();
        $todayPresent = Attendance::where('organization_id', $orgId)->whereDate('date', $today)->whereNotNull('check_in')->count();
        $todayLate = Attendance::where('organization_id', $orgId)->whereDate('date', $today)->where('status', 'late')->count();

        // Pending Approvals
        $pendingLeaves = LeaveRequest::where('organization_id', $orgId)->where('status', 'pending')->count();
        $pendingExpenses = ExpenseClaim::where('organization_id', $orgId)->where('status', 'pending')->count();
        $activeOpenings = JobOpening::where('organization_id', $orgId)->where('status', 'active')->count();
        $activeCandidates = Candidate::where('organization_id', $orgId)->whereNotIn('stage', ['joined', 'rejected'])->count();

        // Recent System Activity
        $recentAuditLogs = AuditLog::where('organization_id', $orgId)
            ->with('actor:id,name,email')
            ->orderBy('created_at', 'desc')
            ->limit(8)
            ->get();

        return response()->json([
            'organization' => Organization::find($orgId),
            'headcount' => [
                'total' => $totalUsers,
                'active' => $activeUsers,
                'on_leave' => $onLeaveUsers,
                'inactive' => $inactiveUsers,
                'departments' => max(1, $departmentsCount),
                'managers' => $managersCount,
                'team_leaders' => $teamLeadersCount,
            ],
            'attendance' => [
                'today_present' => $todayPresent,
                'today_late' => $todayLate,
                'on_time_rate' => $todayPresent > 0 ? round((($todayPresent - $todayLate) / $todayPresent) * 100) : 100,
            ],
            'pending_actions' => [
                'leave_requests' => $pendingLeaves,
                'expense_claims' => $pendingExpenses,
                'total_pending' => $pendingLeaves + $pendingExpenses,
            ],
            'recruitment' => [
                'active_openings' => $activeOpenings,
                'active_candidates' => $activeCandidates,
            ],
            'recent_activity' => $recentAuditLogs,
        ]);
    }

    /**
     * Organization User Management List
     */
    public function users(Request $request)
    {
        $admin = $request->user();
        $query = User::where('organization_id', $admin->organization_id)
            ->with(['role', 'manager:id,name,email']);

        if ($request->has('search') && $request->search != '') {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('employee_code', 'like', "%{$search}%")
                  ->orWhere('department', 'like', "%{$search}%");
            });
        }

        if ($request->has('role') && $request->role != '') {
            $role = Role::where('name', $request->role)->first();
            if ($role) {
                $query->where('role_id', $role->id);
            }
        }

        if ($request->has('status') && $request->status != '') {
            $query->where('status', $request->status);
        }

        $users = $query->orderBy('name')->get();
        return response()->json(['users' => $users]);
    }

    /**
     * Update User Role
     */
    public function updateUserRole(Request $request, $id)
    {
        $admin = $request->user();
        $request->validate([
            'role' => 'required|string|exists:roles,name',
        ]);

        $targetUser = User::where('organization_id', $admin->organization_id)->where('id', $id)->first();
        if (!$targetUser) {
            return response()->json(['message' => 'User not found in organization'], 404);
        }

        // Prevent modifying Master Admin role
        if (($targetUser->email === 'admin@blueboxx.com' || ($targetUser->role && strtolower($targetUser->role->name) === 'admin')) && $request->role !== 'admin') {
            return response()->json(['message' => 'The Primary Admin account is permanent and its role cannot be modified.'], 403);
        }

        $role = Role::where('name', $request->role)->first();
        $oldRole = $targetUser->role->name ?? 'None';
        $targetUser->role_id = $role->id;
        $targetUser->save();

        AuditLog::create([
            'organization_id' => $admin->organization_id,
            'actor_id' => $admin->id,
            'action' => 'admin_change_user_role',
            'target_type' => User::class,
            'target_id' => $targetUser->id,
            'payload' => ['old_role' => $oldRole, 'new_role' => $role->name],
        ]);

        NotificationService::create(
            $admin->organization_id,
            $targetUser->id,
            'Role Updated',
            "Your system access role has been updated to {$role->display_name} by an Administrator.",
            'info'
        );

        return response()->json([
            'message' => "User role successfully updated to {$role->display_name}",
            'user' => $targetUser->load('role')
        ]);
    }

    /**
     * Update User Status (active, inactive, on_leave, terminated)
     */
    public function updateUserStatus(Request $request, $id)
    {
        $admin = $request->user();
        $request->validate([
            'status' => 'required|in:active,inactive,on_leave,resigned,terminated',
            'reason' => 'nullable|string',
        ]);

        $targetUser = User::where('organization_id', $admin->organization_id)->where('id', $id)->first();
        if (!$targetUser) {
            return response()->json(['message' => 'User not found in organization'], 404);
        }

        // Prevent deactivating or terminating Master Admin
        if (($targetUser->email === 'admin@blueboxx.com' || ($targetUser->role && strtolower($targetUser->role->name) === 'admin')) && $request->status !== 'active') {
            return response()->json(['message' => 'The Primary Admin account is permanent and must remain active.'], 403);
        }

        $oldStatus = $targetUser->status;
        $targetUser->status = $request->status;
        $targetUser->save();

        AuditLog::create([
            'organization_id' => $admin->organization_id,
            'actor_id' => $admin->id,
            'action' => 'admin_update_user_status',
            'target_type' => User::class,
            'target_id' => $targetUser->id,
            'payload' => [
                'old_status' => $oldStatus,
                'new_status' => $request->status,
                'reason' => $request->reason ?? 'Admin status change',
            ],
        ]);

        return response()->json([
            'message' => "User status transitioned from {$oldStatus} to {$request->status}",
            'user' => $targetUser
        ]);
    }

    /**
     * Assign Manager to User
     */
    public function assignManager(Request $request, $id)
    {
        $admin = $request->user();
        $request->validate([
            'manager_id' => 'nullable|exists:users,id',
        ]);

        $targetUser = User::where('organization_id', $admin->organization_id)->where('id', $id)->first();
        if (!$targetUser) {
            return response()->json(['message' => 'User not found in organization'], 404);
        }

        if ($request->manager_id) {
            $manager = User::where('organization_id', $admin->organization_id)->where('id', $request->manager_id)->first();
            if (!$manager) {
                return response()->json(['message' => 'Assigned manager must belong to your organization'], 400);
            }
        }

        $targetUser->manager_id = $request->manager_id;
        $targetUser->save();

        AuditLog::create([
            'organization_id' => $admin->organization_id,
            'actor_id' => $admin->id,
            'action' => 'admin_assign_manager',
            'target_type' => User::class,
            'target_id' => $targetUser->id,
            'payload' => ['manager_id' => $request->manager_id],
        ]);

        return response()->json([
            'message' => 'Reporting manager assigned successfully',
            'user' => $targetUser->load('manager')
        ]);
    }

    /**
     * Get & Update Organization Settings
     */
    public function getOrganization(Request $request)
    {
        $admin = $request->user();
        $org = Organization::find($admin->organization_id);
        return response()->json(['organization' => $org]);
    }

    public function updateOrganization(Request $request)
    {
        $admin = $request->user();
        $org = Organization::find($admin->organization_id);

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'settings' => 'sometimes|array',
        ]);

        if ($request->has('name')) {
            $org->name = $request->name;
        }

        if ($request->has('settings')) {
            $currentSettings = $org->settings ?? [];
            $org->settings = array_merge($currentSettings, $request->settings);
        }

        $org->save();

        AuditLog::create([
            'organization_id' => $admin->organization_id,
            'actor_id' => $admin->id,
            'action' => 'admin_update_organization_settings',
            'target_type' => Organization::class,
            'target_id' => $org->id,
            'payload' => ['name' => $org->name],
        ]);

        return response()->json([
            'message' => 'Organization configuration updated successfully',
            'organization' => $org
        ]);
    }

    /**
     * Public branding endpoint (for login page & unauthenticated portal previews)
     */
    public function getBranding(Request $request)
    {
        $org = Organization::first();
        $settings = $org ? ($org->settings ?? []) : [];

        return response()->json([
            'organization_name' => $org ? $org->name : 'Blueboxx HRMS',
            'organization_code' => $org ? $org->code : 'BLUEBOXX',
            'logo_url' => $settings['logo_url'] ?? '/images/logoblue.png',
            'icon_logo_url' => $settings['icon_logo_url'] ?? '/images/Boxxlogo.png',
        ]);
    }

    /**
     * Update organization logo / branding
     * Restricted: Only Admin
     */
    public function updateLogo(Request $request)
    {
        $admin = $request->user();
        $org = Organization::find($admin->organization_id);

        if (!$org) {
            return response()->json(['message' => 'Organization not found'], 404);
        }

        $request->validate([
            'logo' => 'nullable|string', // Base64 data URI or image URL
            'icon_logo' => 'nullable|string',
            'logo_file' => 'nullable|image|max:5120', // Up to 5MB image
            'icon_file' => 'nullable|image|max:5120',
        ]);

        $settings = $org->settings ?? [];

        // Convert uploaded file to base64 data URI for zero-dependency portability
        if ($request->hasFile('logo_file')) {
            $file = $request->file('logo_file');
            $data = file_get_contents($file->getRealPath());
            $mime = $file->getMimeType();
            $settings['logo_url'] = 'data:' . $mime . ';base64,' . base64_encode($data);
        } elseif ($request->filled('logo')) {
            $settings['logo_url'] = $request->logo;
        }

        if ($request->hasFile('icon_file')) {
            $file = $request->file('icon_file');
            $data = file_get_contents($file->getRealPath());
            $mime = $file->getMimeType();
            $settings['icon_logo_url'] = 'data:' . $mime . ';base64,' . base64_encode($data);
        } elseif ($request->filled('icon_logo')) {
            $settings['icon_logo_url'] = $request->icon_logo;
        }

        $org->settings = $settings;
        $org->save();

        AuditLog::create([
            'organization_id' => $admin->organization_id,
            'actor_id' => $admin->id,
            'action' => 'organization_logo_updated',
            'target_type' => Organization::class,
            'target_id' => $org->id,
            'payload' => [
                'has_logo' => !empty($settings['logo_url']),
                'has_icon' => !empty($settings['icon_logo_url']),
            ],
        ]);

        return response()->json([
            'message' => 'Organization logo updated successfully! It will now be visible to all users.',
            'logo_url' => $settings['logo_url'] ?? '/images/logoblue.png',
            'icon_logo_url' => $settings['icon_logo_url'] ?? '/images/Boxxlogo.png',
            'organization' => $org,
        ]);
    }
}
