<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\Attendance;
use App\Models\LeaveRequest;
use App\Models\EmployeeRiskScore;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Carbon\Carbon;

class ScanAttendanceAnomalies implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $organizationId;

    public function __construct($organizationId = null)
    {
        $this->organizationId = $organizationId;
    }

    public function handle(): void
    {
        $usersQuery = User::where('status', 'active');
        if ($this->organizationId) {
            $usersQuery->where('organization_id', $this->organizationId);
        }

        $users = $usersQuery->get();

        foreach ($users as $user) {
            $score = 0;
            $factors = [];

            // 1. Late check-in frequency over last 30 days
            $last30Days = Carbon::today()->subDays(30);
            $lateCount = Attendance::where('user_id', $user->id)
                ->where('date', '>=', $last30Days)
                ->where('status', 'late')
                ->count();

            if ($lateCount >= 4) {
                $score += 35;
                $factors[] = "Repeated late check-ins over past 30 days ({$lateCount} instances)";
            } elseif ($lateCount >= 2) {
                $score += 18;
                $factors[] = "Occasional late check-ins ({$lateCount} instances)";
            }

            // 2. Unplanned / Short notice leave frequency
            $recentLeaves = LeaveRequest::where('user_id', $user->id)
                ->where('start_date', '>=', $last30Days)
                ->where('status', 'approved')
                ->get();

            if ($recentLeaves->count() >= 3) {
                $score += 30;
                $factors[] = "High leave frequency in past 30 days ({$recentLeaves->count()} requests)";
            }

            // 3. Weekend-adjacent leave clustering (Friday / Monday leaves)
            $weekendAdjacentCount = 0;
            foreach ($recentLeaves as $leave) {
                $startDay = Carbon::parse($leave->start_date)->dayOfWeek;
                $endDay = Carbon::parse($leave->end_date)->dayOfWeek;
                if ($startDay === Carbon::FRIDAY || $endDay === Carbon::MONDAY) {
                    $weekendAdjacentCount++;
                }
            }

            if ($weekendAdjacentCount >= 2) {
                $score += 25;
                $factors[] = "Leave clustering adjacent to weekends detected ({$weekendAdjacentCount} instances)";
            }

            // 4. Absence frequency
            $absentCount = Attendance::where('user_id', $user->id)
                ->where('date', '>=', $last30Days)
                ->where('status', 'absent')
                ->count();

            if ($absentCount >= 2) {
                $score += 20;
                $factors[] = "Unexcused absences in past 30 days ({$absentCount} instances)";
            }

            if (empty($factors)) {
                $factors[] = "Consistent daily punctuality and balanced leave pattern";
            }

            $score = min(100, $score);
            $level = $score >= 60 ? 'High' : ($score >= 35 ? 'Medium' : 'Low');

            EmployeeRiskScore::create([
                'organization_id' => $user->organization_id,
                'user_id' => $user->id,
                'risk_score' => $score,
                'risk_level' => $level,
                'contributing_factors' => $factors,
                'calculated_at' => Carbon::now(),
            ]);
        }
    }
}
