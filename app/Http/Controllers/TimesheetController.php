<?php

namespace App\Http\Controllers;

use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Http\Request;

class TimesheetController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $role = $user->getCanonicalRole();

        $query = Timesheet::where('organization_id', $user->organization_id)->with(['user', 'approver']);

        if ($role === 'employee') {
            $query->where('user_id', $user->id);
        } elseif (in_array($role, ['manager', 'team_leader'])) {
            $teamEmpIds = User::where('organization_id', $user->organization_id)->where('manager_id', $user->id)->pluck('id')->toArray();
            $teamEmpIds[] = $user->id;
            $query->whereIn('user_id', $teamEmpIds);
        }

        $timesheets = $query->orderBy('date', 'desc')->get();
        return response()->json(['timesheets' => $timesheets]);
    }

    public function store(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'date' => 'required|date',
            'project_name' => 'required|string',
            'task_description' => 'required|string',
            'hours' => 'required|numeric|min:0.5|max:24',
            'billable' => 'nullable|boolean',
        ]);

        $timesheet = Timesheet::create([
            'organization_id' => $user->organization_id,
            'user_id' => $user->id,
            'date' => $request->date,
            'project_name' => $request->project_name,
            'task_description' => $request->task_description,
            'hours' => $request->hours,
            'billable' => $request->billable ?? true,
            'status' => 'submitted',
        ]);

        return response()->json(['message' => 'Timesheet entry logged successfully', 'timesheet' => $timesheet], 201);
    }
}
