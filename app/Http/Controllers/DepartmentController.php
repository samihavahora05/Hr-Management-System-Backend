<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\User;
use App\Models\AuditLog;
use Illuminate\Http\Request;

class DepartmentController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        // Ensure default company departments exist in departments database table
        $defaultDepts = [
            'Engineering',
            'Human Resources',
            'Product Management',
            'Marketing',
            'Finance',
            'Executive',
            'Sales',
            'Legal & Compliance',
            'Customer Success',
            'Operations',
        ];

        foreach ($defaultDepts as $dName) {
            Department::firstOrCreate([
                'organization_id' => $user->organization_id,
                'name' => $dName,
            ]);
        }

        // Also check if any employee has a custom department assigned
        $empDepts = User::where('organization_id', $user->organization_id)
            ->whereNotNull('department')
            ->pluck('department')
            ->unique();

        foreach ($empDepts as $eDept) {
            if ($eDept) {
                Department::firstOrCreate([
                    'organization_id' => $user->organization_id,
                    'name' => trim($eDept),
                ]);
            }
        }

        // Fetch all departments for this organization directly from the departments table
        $deptRecords = Department::where('organization_id', $user->organization_id)
            ->orderBy('name', 'asc')
            ->get();

        $deptNames = $deptRecords->pluck('name')->toArray();

        // Calculate active headcount per department from users table
        $byDept = [];
        foreach ($deptRecords as $dept) {
            $count = User::where('organization_id', $user->organization_id)
                ->where('department', $dept->name)
                ->count();

            $byDept[] = [
                'id' => $dept->id,
                'department' => $dept->name,
                'code' => $dept->code,
                'count' => $count,
            ];
        }

        return response()->json([
            'departments' => $deptNames,
            'by_department' => $byDept,
        ]);
    }

    public function store(Request $request)
    {
        $actor = $request->user();
        $roleName = strtolower($actor->role->name ?? 'employee');

        if (!in_array($roleName, ['admin', 'hr'])) {
            return response()->json(['message' => 'Unauthorized: Only HR or Admin can create departments'], 403);
        }

        $request->validate([
            'name' => 'required|string|max:100',
        ]);

        $name = trim($request->name);

        $exists = Department::where('organization_id', $actor->organization_id)
            ->whereRaw('LOWER(name) = ?', [strtolower($name)])
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => "Department '{$name}' already exists in your organization."
            ], 422);
        }

        $department = Department::create([
            'organization_id' => $actor->organization_id,
            'name' => $name,
        ]);

        AuditLog::create([
            'organization_id' => $actor->organization_id,
            'actor_id' => $actor->id,
            'action' => 'create_department',
            'target_type' => 'Department',
            'target_id' => $department->id,
            'payload' => ['department' => $name],
        ]);

        return response()->json([
            'message' => "Department '{$name}' created and saved to database successfully.",
            'department' => $department,
        ], 201);
    }
}
