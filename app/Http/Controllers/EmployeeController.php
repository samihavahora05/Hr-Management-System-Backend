<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Role;
use App\Models\EmployeeDocument;
use App\Models\LeaveType;
use App\Models\LeaveBalance;
use App\Models\AuditLog;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class EmployeeController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $roleName = strtolower($user->role->name ?? 'employee');

        $query = User::where('organization_id', $user->organization_id)
            ->with(['role', 'manager']);

        // Scoping based on explicit role
        if ($roleName === 'employee') {
            // Employee sees only self
            $query->where('id', $user->id);
        } elseif ($roleName === 'team_leader') {
            // Team Leader sees all employees assigned to them by manager + self
            $query->where(function ($q) use ($user) {
                $q->where('manager_id', $user->id)
                  ->orWhere('id', $user->id);
            });
        } elseif (($roleName === 'manager' || $roleName === 'company_manager') && $request->has('team_only')) {
            // Scope to direct team only if explicitly requested
            $tlIds = User::where('organization_id', $user->organization_id)
                ->where('manager_id', $user->id)
                ->pluck('id')
                ->toArray();

            $query->where(function ($q) use ($user, $tlIds) {
                $q->where('manager_id', $user->id)
                  ->orWhereIn('manager_id', $tlIds)
                  ->orWhere('id', $user->id);
            });
        }
        // Admin, HR, Manager, and Company Manager see ALL employees in their organization by default

        if ($request->has('department') && $request->department != '') {
            $query->where('department', $request->department);
        }

        if ($request->has('status') && $request->status != '') {
            $query->where('status', $request->status);
        }

        if ($request->has('search') && $request->search != '') {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('employee_code', 'like', "%{$search}%")
                  ->orWhere('designation', 'like', "%{$search}%");
            });
        }

        $employees = $query->orderBy('name')->get();

        // Mask confidential financial fields for non-HR/Admin roles
        if (!in_array($roleName, ['admin', 'hr'])) {
            $employees->makeHidden(['base_salary']);
        }

        return response()->json(['employees' => $employees]);
    }

    public function store(Request $request)
    {
        $actor = $request->user();
        $roleName = strtolower($actor->role->name ?? 'employee');

        // Only Admin and HR can create employees
        if (!in_array($roleName, ['admin', 'hr'])) {
            return response()->json(['message' => 'Unauthorized: Only HR or Admin can onboard employees'], 403);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'nullable|string|min:6',
            'role' => 'required|string',
            'department' => 'required|string',
            'designation' => 'required|string',
            'joining_date' => 'required|date',
            'base_salary' => 'required|numeric|min:0',
            'phone' => 'nullable|string',
            'manager_id' => 'nullable|exists:users,id',
            'shift_id' => 'nullable|exists:shifts,id',
        ]);

        $role = Role::where('name', $request->role)->first();

        // Generate dynamic sequential employee code (reuses empty slots from removed employees)
        $employeeCode = User::generateNextEmployeeCode($actor->organization_id);

        $plainPassword = $request->filled('password') ? $request->password : 'password123';

        $employee = User::create([
            'organization_id' => $actor->organization_id,
            'role_id' => $role ? $role->id : null,
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($plainPassword),
            'employee_code' => $employeeCode,
            'department' => $request->department,
            'designation' => $request->designation,
            'joining_date' => $request->joining_date,
            'status' => 'active',
            'phone' => $request->phone,
            'base_salary' => $request->base_salary,
            'manager_id' => $request->manager_id,
            'shift_id' => $request->shift_id,
            'probation_status' => 'probation',
        ]);

        // Auto-allocate initial leave balances
        $leaveTypes = LeaveType::where(function ($q) use ($actor) {
            $q->where('organization_id', $actor->organization_id)
              ->orWhereNull('organization_id');
        })->get();

        if ($leaveTypes->isEmpty()) {
            $leaveTypes = LeaveType::all();
        }

        foreach ($leaveTypes as $lt) {
            $quota = $lt->max_days_per_year ?? $lt->annual_quota ?? 12;
            LeaveBalance::create([
                'organization_id' => $actor->organization_id,
                'user_id' => $employee->id,
                'leave_type_id' => $lt->id,
                'allocated' => $quota,
                'used' => 0,
                'remaining' => $quota,
            ]);
        }

        AuditLog::create([
            'organization_id' => $actor->organization_id,
            'actor_id' => $actor->id,
            'action' => 'create_employee',
            'target_type' => User::class,
            'target_id' => $employee->id,
            'payload' => ['employee_code' => $employeeCode, 'email' => $employee->email],
        ]);

        NotificationService::notifyManagementChain(
            $employee,
            'New Employee Onboarded',
            "{$employee->name} ({$employee->designation}) has joined the {$employee->department} department.",
            'info',
            '/hr/employees'
        );

        return response()->json([
            'message' => 'Employee created successfully',
            'employee' => $employee->load(['role', 'manager'])
        ], 201);
    }

    public function show(Request $request, $id)
    {
        $actor = $request->user();
        $roleName = strtolower($actor->role->name ?? 'employee');

        // Check if employee exists in the same organization
        $employee = User::where('organization_id', $actor->organization_id)
            ->where('id', $id)
            ->with(['role', 'manager', 'documents', 'leaveBalances.leaveType', 'latestRiskScore'])
            ->first();

        if (!$employee) {
            return response()->json(['message' => 'Employee record not found'], 404);
        }

        // Ownership and Role checks:
        // Employee role can ONLY view self profile
        if ($roleName === 'employee' && $employee->id !== $actor->id) {
            return response()->json(['message' => 'Unauthorized: You can only view your own profile'], 403);
        }

        // Manager role can view self or direct reports
        if ($roleName === 'manager' && $employee->id !== $actor->id && $employee->manager_id !== $actor->id) {
            return response()->json(['message' => 'Unauthorized: Manager can only view direct team members'], 403);
        }

        // Mask base salary for non-Admin/HR roles
        if (!in_array($roleName, ['admin', 'hr'])) {
            $employee->makeHidden(['base_salary']);
        }

        return response()->json(['employee' => $employee]);
    }

    public function update(Request $request, $id)
    {
        $actor = $request->user();
        $roleName = strtolower($actor->role->name ?? 'employee');

        $employee = User::where('organization_id', $actor->organization_id)
            ->where('id', $id)
            ->first();

        if (!$employee) {
            return response()->json(['message' => 'Employee record not found'], 404);
        }

        // Only Admin/HR can update arbitrary fields. Employee/Manager can update specific fields.
        if (!in_array($roleName, ['admin', 'hr'])) {
            if (in_array($roleName, ['manager', 'company_manager', 'team_leader'])) {
                if (($request->has('remove_from_team') && $request->remove_from_team) || ($request->has('manager_id') && ($request->manager_id === null || $request->manager_id === 'null' || $request->manager_id === 0))) {
                    $employee->manager_id = null;
                    $employee->save();
                    return response()->json(['message' => 'Employee removed from team successfully', 'employee' => $employee]);
                }

                if ($request->has('manager_id') && (int)$request->manager_id === $actor->id) {
                    $employee->manager_id = $actor->id;
                    $employee->save();
                    return response()->json(['message' => 'Employee added to your team successfully', 'employee' => $employee]);
                }
            }

            if ($employee->id !== $actor->id) {
                return response()->json(['message' => 'Unauthorized: Insufficient permissions to update employee record'], 403);
            }
            // Self update allowed only for phone/avatar
            $request->validate([
                'phone' => 'nullable|string',
                'avatar' => 'nullable|string',
            ]);
            $employee->fill($request->only(['phone', 'avatar']));
            $employee->save();
            return response()->json(['message' => 'Profile updated successfully', 'employee' => $employee]);
        }

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'password' => 'nullable|string|min:6',
            'department' => 'sometimes|string',
            'designation' => 'sometimes|string',
            'status' => 'sometimes|in:active,inactive,on_leave',
            'phone' => 'nullable|string',
            'base_salary' => 'sometimes|numeric|min:0',
        ]);

        if ($request->has('role')) {
            $role = Role::where('name', $request->role)->first();
            if ($role) {
                $employee->role_id = $role->id;
            }
        }

        if ($request->filled('password')) {
            $employee->password = Hash::make($request->password);
        }

        $employee->fill($request->only(['name', 'department', 'designation', 'status', 'phone', 'base_salary', 'manager_id', 'shift_id']));
        $employee->save();

        AuditLog::create([
            'organization_id' => $actor->organization_id,
            'actor_id' => $actor->id,
            'action' => 'update_employee',
            'target_type' => User::class,
            'target_id' => $employee->id,
            'payload' => $request->except(['password', 'remember_token']),
        ]);

        return response()->json([
            'message' => 'Employee updated successfully',
            'employee' => $employee->load(['role', 'manager'])
        ]);
    }

    public function uploadDocument(Request $request, $id)
    {
        $actor = $request->user();
        $roleName = strtolower($actor->role->name ?? 'employee');

        $employee = User::where('organization_id', $actor->organization_id)
            ->where('id', $id)
            ->first();

        if (!$employee) {
            return response()->json(['message' => 'Employee record not found'], 404);
        }

        // Only Admin/HR or employee self uploading for self
        if (!in_array($roleName, ['admin', 'hr']) && $employee->id !== $actor->id) {
            return response()->json(['message' => 'Unauthorized to upload documents for this employee'], 403);
        }

        $request->validate([
            'title' => 'required|string|max:255',
            'type' => 'required|string|max:50',
            'file' => 'nullable|file|mimes:pdf,doc,docx,jpg,jpeg,png|max:10240',
            'file_url' => 'nullable|string',
        ]);

        $storedPath = null;
        if ($request->hasFile('file')) {
            $uploaded = $request->file('file');
            $safeName = \Illuminate\Support\Str::uuid()->toString() . '.' . $uploaded->getClientOriginalExtension();
            $storedPath = $uploaded->storeAs('documents/' . $actor->organization_id, $safeName, 'local');
        } elseif ($request->filled('file_url')) {
            $storedPath = $request->file_url;
        } else {
            return response()->json(['message' => 'A valid document file or file_url is required.'], 422);
        }

        $doc = EmployeeDocument::create([
            'organization_id' => $actor->organization_id,
            'user_id' => $employee->id,
            'title' => $request->title,
            'type' => $request->type,
            'file_url' => $storedPath,
        ]);

        return response()->json([
            'message' => 'Document uploaded successfully',
            'document' => $doc
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $actor = $request->user();
        $roleName = strtolower($actor->role->name ?? 'employee');

        // Only Admin and HR can delete employees
        if (!in_array($roleName, ['admin', 'hr'])) {
            return response()->json(['message' => 'Unauthorized: Only HR or Admin can remove users'], 403);
        }

        if ((int)$id === (int)$actor->id) {
            return response()->json(['message' => 'You cannot remove your own user account.'], 400);
        }

        $employee = User::where('organization_id', $actor->organization_id)
            ->where('id', $id)
            ->first();

        if (!$employee) {
            return response()->json(['message' => 'User record not found'], 404);
        }

        // Protect Admin accounts from deletion (Permanent accounts)
        $empRoleName = strtolower($employee->role->name ?? '');
        if ($empRoleName === 'admin' || $employee->email === 'admin@blueboxx.com') {
            return response()->json(['message' => 'The Primary Admin account is permanent and cannot be removed from the system.'], 403);
        }

        $empName = $employee->name;
        $empEmail = $employee->email;

        $employee->delete();

        AuditLog::create([
            'organization_id' => $actor->organization_id,
            'actor_id' => $actor->id,
            'action' => 'delete_employee',
            'target_type' => User::class,
            'target_id' => (int)$id,
            'payload' => ['name' => $empName, 'email' => $empEmail],
        ]);

        return response()->json(['message' => "User {$empName} removed successfully"]);
    }
}
