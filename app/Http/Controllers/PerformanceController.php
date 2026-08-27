<?php

namespace App\Http\Controllers;

use App\Models\PerformanceCycle;
use App\Models\Goal;
use App\Models\PerformanceReview;
use App\Models\User;
use App\Models\AuditLog;
use App\Services\NotificationService;
use Illuminate\Http\Request;

class PerformanceController extends Controller
{
    // Performance Cycles
    public function getCycles(Request $request)
    {
        $user = $request->user();
        $cycles = PerformanceCycle::where('organization_id', $user->organization_id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['cycles' => $cycles]);
    }

    public function storeCycle(Request $request)
    {
        $actor = $request->user();
        if (!in_array($actor->getCanonicalRole(), ['admin', 'hr'])) {
            return response()->json(['message' => 'Unauthorized: Only Admin or HR can launch performance cycles'], 403);
        }

        $request->validate([
            'title' => 'required|string',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        $cycle = PerformanceCycle::create([
            'organization_id' => $actor->organization_id,
            'title' => $request->title,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'status' => 'active',
        ]);

        return response()->json(['message' => 'Performance cycle launched successfully', 'cycle' => $cycle], 201);
    }

    // Goals List (Scoped)
    public function getGoals(Request $request)
    {
        $user = $request->user();
        $role = $user->getCanonicalRole();

        $query = Goal::where('organization_id', $user->organization_id)->with(['user', 'cycle']);

        if ($role === 'employee') {
            $query->where('user_id', $user->id);
        } elseif ($role === 'team_leader') {
            $teamEmpIds = User::where('organization_id', $user->organization_id)->where('manager_id', $user->id)->pluck('id')->toArray();
            $teamEmpIds[] = $user->id;
            $query->whereIn('user_id', $teamEmpIds);
        } elseif ($role === 'manager') {
            $reportIds = User::where('organization_id', $user->organization_id)->where('manager_id', $user->id)->pluck('id')->toArray();
            $reportIds[] = $user->id;
            $query->whereIn('user_id', $reportIds);
        }

        $goals = $query->orderBy('created_at', 'desc')->get();
        return response()->json(['goals' => $goals]);
    }

    public function storeGoal(Request $request)
    {
        $actor = $request->user();

        $request->validate([
            'title' => 'required|string',
            'user_id' => 'required|exists:users,id',
            'target_value' => 'nullable|numeric',
            'weightage' => 'nullable|integer',
            'cycle_id' => 'nullable|exists:performance_cycles,id',
        ]);

        // Scoping check
        if ($actor->getCanonicalRole() === 'employee' && (int)$request->user_id !== $actor->id) {
            return response()->json(['message' => 'Unauthorized: Employees can only set goals for themselves'], 403);
        }

        $goal = Goal::create([
            'organization_id' => $actor->organization_id,
            'cycle_id' => $request->cycle_id,
            'user_id' => $request->user_id,
            'title' => $request->title,
            'description' => $request->description,
            'target_value' => $request->target_value ?? 100,
            'current_value' => $request->current_value ?? 0,
            'weightage' => $request->weightage ?? 20,
            'status' => 'in_progress',
        ]);

        return response()->json(['message' => 'Goal created successfully', 'goal' => $goal], 201);
    }

    public function updateGoalProgress(Request $request, $id)
    {
        $actor = $request->user();
        $goal = Goal::where('organization_id', $actor->organization_id)->where('id', $id)->first();

        if (!$goal) {
            return response()->json(['message' => 'Goal not found'], 404);
        }

        $request->validate([
            'current_value' => 'required|numeric',
            'status' => 'nullable|in:not_started,in_progress,achieved,partially_achieved,cancelled',
            'manager_comment' => 'nullable|string',
        ]);

        $goal->current_value = $request->current_value;
        if ($request->has('status')) {
            $goal->status = $request->status;
        } elseif ($goal->current_value >= $goal->target_value) {
            $goal->status = 'achieved';
        }

        if ($request->filled('manager_comment')) {
            $goal->manager_comment = $request->manager_comment;
        }

        $goal->save();
        return response()->json(['message' => 'Goal progress updated', 'goal' => $goal]);
    }

    // Performance Reviews (Self & Manager Ratings)
    public function getReviews(Request $request)
    {
        $user = $request->user();
        $role = $user->getCanonicalRole();

        $query = PerformanceReview::where('organization_id', $user->organization_id)
            ->with(['user', 'reviewer', 'cycle']);

        if ($role === 'employee') {
            $query->where('user_id', $user->id);
        } elseif (in_array($role, ['manager', 'team_leader'])) {
            $query->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)->orWhere('reviewer_id', $user->id);
            });
        }

        $reviews = $query->orderBy('created_at', 'desc')->get();
        return response()->json(['reviews' => $reviews]);
    }

    public function submitReview(Request $request, $id)
    {
        $actor = $request->user();
        $review = PerformanceReview::where('organization_id', $actor->organization_id)->where('id', $id)->first();

        if (!$review) {
            return response()->json(['message' => 'Performance review record not found'], 404);
        }

        // Self Review submission
        if ($actor->id === $review->user_id && $review->status === 'pending_self_review') {
            $request->validate([
                'self_rating' => 'required|integer|min:1|max:5',
                'self_feedback' => 'required|string',
            ]);

            $review->self_rating = $request->self_rating;
            $review->self_feedback = $request->self_feedback;
            $review->status = 'pending_manager_review';
            $review->save();

            NotificationService::create(
                $actor->organization_id,
                $review->reviewer_id,
                'Employee Self-Review Submitted',
                "Self review submitted by employee. Action required.",
                'info'
            );

            return response()->json(['message' => 'Self-review submitted successfully', 'review' => $review]);
        }

        // Manager Review submission
        if ($actor->id === $review->reviewer_id || in_array($actor->getCanonicalRole(), ['admin', 'hr'])) {
            $request->validate([
                'manager_rating' => 'required|integer|min:1|max:5',
                'manager_feedback' => 'required|string',
            ]);

            $review->manager_rating = $request->manager_rating;
            $review->manager_feedback = $request->manager_feedback;
            $review->final_rating = round(($review->self_rating + ($request->manager_rating * 2)) / 3, 2);
            $review->status = 'completed';
            $review->save();

            NotificationService::create(
                $actor->organization_id,
                $review->user_id,
                'Performance Review Finalized',
                "Your performance review has been completed with rating: {$review->final_rating}",
                'success'
            );

            return response()->json(['message' => 'Manager review completed successfully', 'review' => $review]);
        }

        return response()->json(['message' => 'Unauthorized action for this review stage'], 403);
    }
}
