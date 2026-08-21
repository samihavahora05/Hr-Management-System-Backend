<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $roleName = strtolower($user->role->name ?? 'employee');

        if ($roleName !== 'admin') {
            return response()->json(['message' => 'Unauthorized: System audit logs require Admin access'], 403);
        }

        $logs = AuditLog::where('organization_id', $user->organization_id)
            ->with('actor')
            ->orderBy('created_at', 'desc')
            ->limit(100)
            ->get();

        return response()->json(['audit_logs' => $logs]);
    }
}
