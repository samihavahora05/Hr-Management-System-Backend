<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use Illuminate\Http\Request;

class AnnouncementController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $roleName = strtolower($user->role->name ?? 'employee');

        $announcements = Announcement::where('organization_id', $user->organization_id)
            ->where(function ($q) use ($roleName) {
                $q->where('target_role', 'all')
                  ->orWhere('target_role', $roleName);
            })
            ->with('author')
            ->orderBy('is_pinned', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['announcements' => $announcements]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $roleName = strtolower($user->role->name ?? 'employee');

        if (!in_array($roleName, ['admin', 'hr'])) {
            return response()->json(['message' => 'Unauthorized: Only HR or Admin can create announcements'], 403);
        }

        $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'target_role' => 'nullable|string',
            'is_pinned' => 'nullable|boolean',
        ]);

        $announcement = Announcement::create([
            'organization_id' => $user->organization_id,
            'author_id' => $user->id,
            'title' => $request->title,
            'content' => $request->content,
            'target_role' => $request->target_role ?? 'all',
            'is_pinned' => $request->is_pinned ?? false,
        ]);

        return response()->json([
            'message' => 'Announcement published successfully',
            'announcement' => $announcement->load('author')
        ], 201);
    }

    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        $roleName = strtolower($user->role->name ?? 'employee');

        if (!in_array($roleName, ['admin', 'hr'])) {
            return response()->json(['message' => 'Unauthorized: Only HR or Admin can delete announcements'], 403);
        }

        $announcement = Announcement::where('organization_id', $user->organization_id)
            ->where('id', $id)
            ->first();

        if (!$announcement) {
            return response()->json(['message' => 'Announcement not found'], 404);
        }

        $announcement->delete();

        return response()->json(['message' => 'Announcement deleted']);
    }
}
