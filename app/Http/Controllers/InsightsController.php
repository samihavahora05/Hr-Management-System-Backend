<?php

namespace App\Http\Controllers;

use App\Models\EmployeeRiskScore;
use App\Jobs\ScanAttendanceAnomalies;
use Illuminate\Http\Request;

class InsightsController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $roleName = strtolower($user->role->name ?? 'employee');

        if (!in_array($roleName, ['admin', 'hr'])) {
            return response()->json(['message' => 'Unauthorized: HR or Admin access required for AI Risk Insights'], 403);
        }

        // Get latest risk score per active employee in organization
        $scores = EmployeeRiskScore::where('organization_id', $user->organization_id)
            ->with(['user.role'])
            ->orderBy('risk_score', 'desc')
            ->get()
            ->unique('user_id')
            ->values();

        $highCount = $scores->where('risk_level', 'High')->count();
        $mediumCount = $scores->where('risk_level', 'Medium')->count();
        $lowCount = $scores->where('risk_level', 'Low')->count();

        return response()->json([
            'summary' => [
                'high_risk' => $highCount,
                'medium_risk' => $mediumCount,
                'low_risk' => $lowCount,
                'total_analyzed' => $scores->count(),
            ],
            'insights' => $scores,
        ]);
    }

    public function triggerScan(Request $request)
    {
        $user = $request->user();
        $roleName = strtolower($user->role->name ?? 'employee');

        if (!in_array($roleName, ['admin', 'hr'])) {
            return response()->json(['message' => 'Unauthorized: Only HR or Admin can trigger AI anomaly scans'], 403);
        }

        (new ScanAttendanceAnomalies($user->organization_id))->handle();

        return response()->json([
            'message' => 'AI Anomaly & Risk Scan completed successfully. Dashboard data refreshed.'
        ]);
    }
}
