<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\AuditLog;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->with(['role', 'organization', 'manager'])->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials do not match our records.'],
            ]);
        }

        if ($user->status !== 'active') {
            return response()->json([
                'message' => 'Your account is inactive. Please contact HR.'
            ], 403);
        }

        $token = $user->createToken('auth_token')->plainTextToken;
        $user->remember_token = $token;
        $user->save();

        // Log audit action
        AuditLog::create([
            'organization_id' => $user->organization_id,
            'actor_id' => $user->id,
            'action' => 'user_login',
            'target_type' => User::class,
            'target_id' => $user->id,
            'payload' => ['ip' => $request->ip()],
        ]);

        $formattedJoiningDate = $user->joining_date ? Carbon::parse($user->joining_date)->format('Y-m-d') : null;

        return response()->json([
            'message' => 'Login successful',
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'employee_code' => $user->employee_code,
                'role' => $user->role ? $user->role->name : 'employee',
                'role_display' => $user->role ? $user->role->display_name : 'Employee',
                'department' => $user->department,
                'designation' => $user->designation,
                'joining_date' => $formattedJoiningDate,
                'status' => $user->status,
                'phone' => $user->phone,
                'avatar' => $user->avatar,
                'base_salary' => $user->base_salary,
                'organization' => $user->organization ? $user->organization->name : 'Organization',
                'organization_id' => $user->organization_id,
                'manager_name' => $user->manager ? $user->manager->name : null,
                'manager_id' => $user->manager_id,
            ]
        ]);
    }

    public function me(Request $request)
    {
        $user = $request->user()->load(['role', 'organization', 'manager']);
        $formattedJoiningDate = $user->joining_date ? Carbon::parse($user->joining_date)->format('Y-m-d') : null;

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'employee_code' => $user->employee_code,
                'role' => $user->role ? $user->role->name : 'employee',
                'role_display' => $user->role ? $user->role->display_name : 'Employee',
                'department' => $user->department,
                'designation' => $user->designation,
                'joining_date' => $formattedJoiningDate,
                'status' => $user->status,
                'phone' => $user->phone,
                'avatar' => $user->avatar,
                'base_salary' => $user->base_salary,
                'organization' => $user->organization ? $user->organization->name : '',
                'organization_id' => $user->organization_id,
                'manager_name' => $user->manager ? $user->manager->name : null,
                'manager_id' => $user->manager_id,
            ]
        ]);
    }

    public function logout(Request $request)
    {
        $user = $request->user();
        if ($user) {
            $user->remember_token = null;
            $user->save();
        }

        return response()->json(['message' => 'Logged out successfully']);
    }

    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required',
            'new_password' => 'required|min:8|confirmed',
        ]);

        $user = $request->user();

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json(['message' => 'Current password is incorrect'], 422);
        }

        $user->password = Hash::make($request->new_password);
        $user->save();

        return response()->json(['message' => 'Password updated successfully']);
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|required|email|unique:users,email,' . $user->id,
            'phone' => 'nullable|string|max:50',
            'department' => 'nullable|string|max:100',
            'designation' => 'nullable|string|max:100',
            'joining_date' => 'nullable|date',
            'avatar' => 'nullable|string',
            'gender' => 'nullable|string|max:20',
            'dob' => 'nullable|date',
        ]);

        $fields = ['name', 'email', 'phone', 'department', 'designation', 'joining_date', 'avatar', 'gender', 'dob'];
        $user->fill($request->only($fields));
        $user->save();

        AuditLog::create([
            'organization_id' => $user->organization_id,
            'actor_id' => $user->id,
            'action' => 'update_profile',
            'target_type' => User::class,
            'target_id' => $user->id,
            'payload' => $request->only($fields),
        ]);

        $user->load(['role', 'organization', 'manager']);
        $formattedJoiningDate = $user->joining_date ? Carbon::parse($user->joining_date)->format('Y-m-d') : null;

        return response()->json([
            'message' => 'Profile updated successfully',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'employee_code' => $user->employee_code,
                'role' => $user->role ? $user->role->name : 'employee',
                'role_display' => $user->role ? $user->role->display_name : 'Employee',
                'department' => $user->department,
                'designation' => $user->designation,
                'joining_date' => $formattedJoiningDate,
                'status' => $user->status,
                'phone' => $user->phone,
                'avatar' => $user->avatar,
                'base_salary' => $user->base_salary,
                'organization' => $user->organization ? $user->organization->name : '',
                'organization_id' => $user->organization_id,
                'manager_name' => $user->manager ? $user->manager->name : null,
                'manager_id' => $user->manager_id,
            ]
        ]);
    }
}
