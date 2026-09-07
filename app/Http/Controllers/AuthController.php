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

        $user = User::where('email', $request->email)->with(['role', 'organization', 'manager', 'shift'])->first();

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

        // Cache the token for instant and multi-session authentication resilience
        \Illuminate\Support\Facades\Cache::put('auth_token_' . $token, $user->id, now()->addDays(30));

        // Automatically process shift auto-checkouts (using employee-specific shift end time)
        app(AttendanceController::class)->processAutoCheckouts($user->organization_id, $user->id);

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
                'role' => $user->getCanonicalRole(),
                'role_display' => $user->getRoleDisplayName(),
                'department' => $user->department,
                'designation' => $user->designation,
                'joining_date' => $formattedJoiningDate,
                'status' => $user->status,
                'phone' => $user->phone,
                'avatar' => $user->avatar,
                'base_salary' => $user->base_salary,
                'organization' => $user->organization ? $user->organization->name : 'Organization',
                'organization_id' => $user->organization_id,
                'organization_logo' => $user->organization?->settings['logo_url'] ?? '/images/logoblue.png',
                'organization_icon_logo' => $user->organization?->settings['icon_logo_url'] ?? '/images/Boxxlogo.png',
                'manager_name' => $user->manager ? $user->manager->name : null,
                'manager_id' => $user->manager_id,
                'shift_id' => $user->shift_id,
                'shift' => $user->shift,
            ]
        ]);
    }

    public function me(Request $request)
    {
        $user = $request->user()->load(['role', 'organization', 'manager', 'shift']);
        // Automatically process shift auto-checkouts (using employee-specific shift end time)
        app(AttendanceController::class)->processAutoCheckouts($user->organization_id, $user->id);
        $formattedJoiningDate = $user->joining_date ? Carbon::parse($user->joining_date)->format('Y-m-d') : null;

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'employee_code' => $user->employee_code,
                'role' => $user->getCanonicalRole(),
                'role_display' => $user->getRoleDisplayName(),
                'department' => $user->department,
                'designation' => $user->designation,
                'joining_date' => $formattedJoiningDate,
                'status' => $user->status,
                'phone' => $user->phone,
                'avatar' => $user->avatar,
                'base_salary' => $user->base_salary,
                'organization' => $user->organization ? $user->organization->name : '',
                'organization_id' => $user->organization_id,
                'organization_logo' => $user->organization?->settings['logo_url'] ?? '/images/logoblue.png',
                'organization_icon_logo' => $user->organization?->settings['icon_logo_url'] ?? '/images/Boxxlogo.png',
                'manager_name' => $user->manager ? $user->manager->name : null,
                'manager_id' => $user->manager_id,
                'shift_id' => $user->shift_id,
                'shift' => $user->shift,
            ]
        ]);
    }

    public function logout(Request $request)
    {
        $user = $request->user();
        $token = $request->bearerToken() 
            ?? $request->header('X-Auth-Token') 
            ?? $request->header('X-Bearer-Token');

        if ($token) {
            \Illuminate\Support\Facades\Cache::forget('auth_token_' . $token);
        }

        if ($user) {
            if ($user->remember_token) {
                \Illuminate\Support\Facades\Cache::forget('auth_token_' . $user->remember_token);
            }
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

        // Invalidate old session cache
        if ($user->remember_token) {
            \Illuminate\Support\Facades\Cache::forget('auth_token_' . $user->remember_token);
        }

        $user->password = Hash::make($request->new_password);
        $user->save();

        AuditLog::create([
            'organization_id' => $user->organization_id,
            'actor_id' => $user->id,
            'action' => 'change_password',
            'target_type' => User::class,
            'target_id' => $user->id,
            'payload' => ['ip' => $request->ip()],
        ]);

        return response()->json(['message' => 'Password updated successfully']);
    }

    public function forgotPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string|min:8|confirmed',
        ], [
            'password.confirmed' => 'The new password and confirmation password do not match.',
            'password.min' => 'The new password must be at least 8 characters long.',
        ]);

        $user = User::where('email', $request->email)->with('role')->first();

        if (!$user) {
            return response()->json([
                'message' => 'No account found with this email address.'
            ], 404);
        }

        if ($user->status !== 'active') {
            return response()->json([
                'message' => 'Your account is inactive. Please contact HR.'
            ], 403);
        }

        // Security Guard: Prevent public reset of Master Administrator accounts
        $canonicalRole = $user->getCanonicalRole();
        if ($canonicalRole === 'admin' || $user->email === 'admin@blueboxx.com') {
            return response()->json([
                'message' => 'Primary Administrator accounts cannot be reset via the public web form. Please use the administrative security console.'
            ], 403);
        }

        // Invalidate previous session tokens
        if ($user->remember_token) {
            \Illuminate\Support\Facades\Cache::forget('auth_token_' . $user->remember_token);
        }
        $user->remember_token = null;
        $user->password = Hash::make($request->password);
        $user->save();

        AuditLog::create([
            'organization_id' => $user->organization_id,
            'actor_id' => $user->id,
            'action' => 'forgot_password_reset',
            'target_type' => User::class,
            'target_id' => $user->id,
            'payload' => [
                'email' => $user->email,
                'ip' => $request->ip()
            ],
        ]);

        return response()->json([
            'message' => 'Password reset successfully. You can now log in with your new password.'
        ], 200);
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();
        $role = $user->getCanonicalRole();

        // Admin & HR can update organizational fields, employees can only update personal contact/avatar fields
        if (in_array($role, ['admin', 'hr'])) {
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
        } else {
            $request->validate([
                'name' => 'sometimes|required|string|max:255',
                'phone' => 'nullable|string|max:50',
                'avatar' => 'nullable|string',
                'gender' => 'nullable|string|max:20',
                'dob' => 'nullable|date',
            ]);
            $fields = ['name', 'phone', 'avatar', 'gender', 'dob'];
        }

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
                'role' => $user->getCanonicalRole(),
                'role_display' => $user->getRoleDisplayName(),
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
