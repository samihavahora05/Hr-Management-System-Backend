<?php

namespace App\Services;

use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;

class TaskPerformanceService
{
    /**
     * Calculate individual employee task performance metrics strictly based on admin-awarded marks.
     */
    public static function calculateEmployeePerformance(User $employee, ?string $startDate = null, ?string $endDate = null): array
    {
        $today = Carbon::today();
        $query = Task::where('assigned_to', $employee->id);
        if (!empty($employee->organization_id)) {
            $query->where('organization_id', $employee->organization_id);
        }

        if ($startDate) {
            $query->where('created_at', '>=', Carbon::parse($startDate)->startOfDay());
        }
        if ($endDate) {
            $query->where('created_at', '<=', Carbon::parse($endDate)->endOfDay());
        }

        $tasks = $query->get();

        $totalAssigned = $tasks->count();
        $inProgress = $tasks->where('status', 'in_progress')->count();
        $todo = $tasks->filter(fn($t) => in_array($t->status, ['todo', 'assigned']))->count();
        $submittedForReview = $tasks->where('status', 'submitted_for_review')->count();
        $needsRevision = $tasks->where('status', 'needs_revision')->count();
        $approved = $tasks->filter(fn($t) => in_array($t->status, ['approved', 'completed']))->count();
        $cancelled = $tasks->where('status', 'cancelled')->count();

        $overdue = $tasks->filter(function ($t) use ($today) {
            return ($t->status === 'overdue') || (
                $t->due_date &&
                Carbon::parse($t->due_date)->isBefore($today) &&
                !in_array($t->status, ['approved', 'completed', 'cancelled'])
            );
        })->count();

        // Calculate marks: for approved/completed tasks, count marks_awarded (or default to 100% of maximum_marks if previously approved)
        $approvedTasks = $tasks->filter(fn($t) => in_array($t->status, ['approved', 'completed']));

        $totalEarnedMarks = (int) $approvedTasks->sum(function ($t) {
            return $t->marks_awarded !== null ? (int)$t->marks_awarded : (int)($t->maximum_marks ?: 100);
        });
        $totalPossibleMarks = (int) $approvedTasks->sum(fn($t) => max(1, $t->maximum_marks ?: 100));

        $performancePercentage = $totalPossibleMarks > 0
            ? round(($totalEarnedMarks / $totalPossibleMarks) * 100, 1)
            : ($approved > 0 ? 100.0 : 0.0);

        // Calculate on-time submission/approval rate
        $onTimeApprovedCount = $tasks->filter(function ($t) {
            if (!in_array($t->status, ['approved', 'completed'])) return false;
            $completionDate = $t->reviewed_at ?: ($t->submitted_at ?: $t->completed_at);
            if ($completionDate && $t->due_date) {
                return Carbon::parse($completionDate)->startOfDay()->lte(Carbon::parse($t->due_date)->startOfDay());
            }
            return true;
        })->count();

        $onTimeRate = $approved > 0 ? round(($onTimeApprovedCount / $approved) * 100, 1) : 100.0;

        // Determine performance evaluation badge & label
        if ($totalAssigned === 0) {
            $rating = 'No Assigned Tasks';
            $badge = 'neutral';
        } elseif ($approvedTasksWithMarks->isEmpty() && $approved === 0) {
            $rating = 'Pending Evaluation';
            $badge = 'neutral';
        } else {
            if ($performancePercentage >= 90 && $overdue === 0) {
                $rating = 'Top Performer';
                $badge = 'emerald';
            } elseif ($performancePercentage >= 75) {
                $rating = 'High Performer';
                $badge = 'blue';
            } elseif ($performancePercentage >= 50) {
                $rating = 'Average Performer';
                $badge = 'amber';
            } else {
                $rating = 'Needs Attention';
                $badge = 'rose';
            }
        }

        return [
            'employee_id' => $employee->id,
            'name' => $employee->name,
            'email' => $employee->email,
            'employee_code' => $employee->employee_code,
            'department' => $employee->department ?? 'General',
            'designation' => $employee->designation ?? 'Staff',
            'avatar' => $employee->avatar,
            'role' => $employee->role?->display_name ?? 'Employee',
            'total_tasks' => $totalAssigned,
            'todo_tasks' => $todo,
            'in_progress_tasks' => $inProgress,
            'submitted_for_review_tasks' => $submittedForReview,
            'needs_revision_tasks' => $needsRevision,
            'approved_tasks' => $approved,
            'completed_tasks' => $approved,
            'overdue_tasks' => $overdue,
            'cancelled_tasks' => $cancelled,
            'total_earned_marks' => $totalEarnedMarks,
            'total_possible_marks' => $totalPossibleMarks,
            'performance_percentage' => $performancePercentage,
            'performance_score' => $performancePercentage,
            'ontime_rate' => $onTimeRate,
            'rating' => $rating,
            'rating_badge' => $badge,
        ];
    }
}
