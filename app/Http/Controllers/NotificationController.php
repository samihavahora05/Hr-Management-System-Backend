<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $notifications = Notification::where('organization_id', $user->organization_id)
            ->where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->limit(30)
            ->get();

        $unreadCount = Notification::where('organization_id', $user->organization_id)
            ->where('user_id', $user->id)
            ->where('is_read', false)
            ->count();

        return response()->json([
            'notifications' => $notifications,
            'unread_count' => $unreadCount
        ]);
    }

    public function markAsRead(Request $request, $id)
    {
        $user = $request->user();
        $notification = Notification::where('organization_id', $user->organization_id)
            ->where('user_id', $user->id)
            ->where('id', $id)
            ->first();

        if ($notification) {
            $notification->is_read = true;
            $notification->save();
        }

        $unreadCount = Notification::where('organization_id', $user->organization_id)
            ->where('user_id', $user->id)
            ->where('is_read', false)
            ->count();

        return response()->json([
            'message' => 'Notification marked as read',
            'notification' => $notification,
            'unread_count' => $unreadCount
        ]);
    }

    public function markAllRead(Request $request)
    {
        $user = $request->user();
        Notification::where('organization_id', $user->organization_id)
            ->where('user_id', $user->id)
            ->where('is_read', false)
            ->update(['is_read' => true]);

        return response()->json([
            'message' => 'All notifications marked as read',
            'unread_count' => 0
        ]);
    }
}
