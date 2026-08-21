<?php

namespace App\Http\Controllers;

use App\Models\EmployeeDocument;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\Request;

class DocumentController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $role = $user->getCanonicalRole();

        $query = EmployeeDocument::where('organization_id', $user->organization_id)->with(['user']);

        if ($role === 'employee') {
            $query->where('user_id', $user->id);
        } elseif (in_array($role, ['manager', 'team_leader'])) {
            $teamEmpIds = User::where('organization_id', $user->organization_id)->where('manager_id', $user->id)->pluck('id')->toArray();
            $teamEmpIds[] = $user->id;
            $query->whereIn('user_id', $teamEmpIds);
        }

        $documents = $query->orderBy('created_at', 'desc')->get();
        return response()->json(['documents' => $documents]);
    }

    public function upload(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'title' => 'required|string',
            'type' => 'required|string',
            'file_url' => 'required|string',
            'user_id' => 'nullable|exists:users,id',
        ]);

        $targetUserId = $user->id;
        if ($request->filled('user_id') && in_array($user->getCanonicalRole(), ['admin', 'hr'])) {
            $targetUserId = $request->user_id;
        }

        $doc = EmployeeDocument::create([
            'organization_id' => $user->organization_id,
            'user_id' => $targetUserId,
            'title' => $request->title,
            'type' => $request->type,
            'file_url' => $request->file_url,
        ]);

        return response()->json(['message' => 'Document uploaded successfully', 'document' => $doc], 201);
    }
}
