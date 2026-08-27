<?php

namespace App\Http\Controllers;

use App\Models\OnboardingChecklist;
use App\Models\User;
use Illuminate\Http\Request;
use Carbon\Carbon;

class ChecklistController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $roleName = strtolower($user->role->name ?? 'employee');

        $query = OnboardingChecklist::where('organization_id', $user->organization_id)
            ->with('user');

        if ($roleName === 'employee') {
            $query->where('user_id', $user->id);
        } elseif ($roleName === 'manager') {
            $directReportIds = $user->directReports()->pluck('id')->toArray();
            $directReportIds[] = $user->id;
            $query->whereIn('user_id', $directReportIds);
        }

        $checklists = $query->orderBy('created_at', 'desc')->get();

        return response()->json(['checklists' => $checklists]);
    }

    public function store(Request $request)
    {
        $actor = $request->user();
        $roleName = strtolower($actor->role->name ?? 'employee');

        if (!in_array($roleName, ['admin', 'hr'])) {
            return response()->json(['message' => 'Unauthorized: Only HR or Admin can create onboarding checklists'], 403);
        }

        $request->validate([
            'user_id' => 'required|exists:users,id',
            'title' => 'required|string',
            'type' => 'required|in:onboarding,offboarding',
            'items' => 'required|array',
        ]);

        $targetUser = User::where('organization_id', $actor->organization_id)
            ->where('id', $request->user_id)
            ->first();

        if (!$targetUser) {
            return response()->json(['message' => 'Target employee not found in organization'], 404);
        }

        $checklist = OnboardingChecklist::create([
            'organization_id' => $actor->organization_id,
            'user_id' => $request->user_id,
            'title' => $request->title,
            'type' => $request->type,
            'status' => 'in_progress',
            'items' => $request->items,
        ]);

        return response()->json([
            'message' => 'Checklist created successfully',
            'checklist' => $checklist->load('user')
        ], 201);
    }

    public function toggleItem(Request $request, $id)
    {
        $user = $request->user();
        $roleName = strtolower($user->role->name ?? 'employee');

        $checklist = OnboardingChecklist::where('organization_id', $user->organization_id)
            ->where('id', $id)
            ->with('user')
            ->first();

        if (!$checklist) {
            return response()->json(['message' => 'Checklist not found'], 404);
        }

        // Authorization check: assigned user, direct manager, or HR/Admin
        if ($roleName === 'employee' && $checklist->user_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized: You can only update your own checklist items'], 403);
        }

        if ($roleName === 'manager' && $checklist->user_id !== $user->id && $checklist->user->manager_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized: Managers can only update checklists for direct team members'], 403);
        }

        $request->validate([
            'item_id' => 'required',
        ]);

        $items = $checklist->items ?? [];
        $allCompleted = true;

        foreach ($items as &$item) {
            if ($item['id'] == $request->item_id) {
                $item['completed'] = !($item['completed'] ?? false);
                $item['completed_at'] = $item['completed'] ? Carbon::now()->toDateString() : null;
            }
            if (!($item['completed'] ?? false)) {
                $allCompleted = false;
            }
        }

        $checklist->items = $items;
        $checklist->status = $allCompleted ? 'completed' : 'in_progress';
        $checklist->save();

        return response()->json([
            'message' => 'Item updated',
            'checklist' => $checklist
        ]);
    }
}
