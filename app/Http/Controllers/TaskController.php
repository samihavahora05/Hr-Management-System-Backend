<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Carbon\Carbon;

class TaskController extends Controller
{
    /**
     * Helper to get user's canonical role name
     */
    private function getRoleName(User $user): string
    {
        return $user->getCanonicalRole();
    }

    /**
     * Helper to retrieve array of employee IDs authorized under assigner's scope
     */
    private function getAuthorizedAssigneeIds(User $actor): array
    {
        $role = $this->getRoleName($actor);

        if (in_array($role, ['admin', 'hr'])) {
            // Admin & HR can assign tasks to any active user in the organization
            return User::where('organization_id', $actor->organization_id)
                ->where('status', 'active')
                ->pluck('id')
                ->toArray();
        }

        if ($role === 'manager') {
            // Company Manager can assign tasks to Team Leaders, direct reports, or self
            $teamLeaderIds = User::where('organization_id', $actor->organization_id)
                ->where('manager_id', $actor->id)
                ->pluck('id')
                ->toArray();

            $teamEmpIds = User::where('organization_id', $actor->organization_id)
                ->whereIn('manager_id', array_merge([$actor->id], $teamLeaderIds))
                ->pluck('id')
                ->toArray();

            return array_values(array_unique(array_merge([$actor->id], $teamLeaderIds, $teamEmpIds)));
        }

        if ($role === 'team_leader') {
            // Team Leader can assign tasks to direct team members or self
            $teamEmpIds = User::where('organization_id', $actor->organization_id)
                ->where('manager_id', $actor->id)
                ->pluck('id')
                ->toArray();

            return array_values(array_unique(array_merge([$actor->id], $teamEmpIds)));
        }

        return [$actor->id];
    }

    /**
     * Get list of assignable users strictly based on current user's role hierarchy
     */
    public function assignableUsers(Request $request)
    {
        $actor = $request->user();
        $assigneeIds = $this->getAuthorizedAssigneeIds($actor);

        $users = User::whereIn('id', $assigneeIds)
            ->select('id', 'name', 'email', 'employee_code', 'department', 'designation', 'role_id')
            ->with('role:id,name,display_name')
            ->orderBy('name', 'asc')
            ->get();

        return response()->json(['users' => $users]);
    }

    /**
     * Get scoped task list & statistics (Single Source of Truth)
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $role = $this->getRoleName($user);

        if (Task::where('organization_id', $user->organization_id)->count() === 0) {
            $this->seedInitialTasks($user->organization_id);
        }

        $query = Task::where('organization_id', $user->organization_id)
            ->with([
                'assigner:id,name,email,avatar,role_id',
                'assigner.role:id,name,display_name',
                'assignedTo:id,name,email,avatar,department,designation,role_id',
                'assignedTo.role:id,name,display_name'
            ]);

        // Strict Role Scoping
        if ($role === 'employee') {
            // Employees see ONLY tasks assigned directly to them
            $query->where('assigned_to', $user->id);
        } elseif ($role === 'team_leader') {
            // Team Leader sees tasks assigned to them by Manager + tasks created by Team Leader for team employees
            $teamEmpIds = User::where('organization_id', $user->organization_id)
                ->where('manager_id', $user->id)
                ->pluck('id')
                ->toArray();
            
            $query->where(function ($q) use ($user, $teamEmpIds) {
                $q->where('assigned_to', $user->id)
                  ->orWhere('assigner_id', $user->id)
                  ->orWhereIn('assigned_to', $teamEmpIds);
            });
        } elseif ($role === 'manager') {
            // Company Manager sees tasks received from Admin + tasks assigned to Team Leaders + tasks of employees under manager's teams
            $teamLeaderIds = User::where('organization_id', $user->organization_id)
                ->where('manager_id', $user->id)
                ->pluck('id')
                ->toArray();

            $teamEmpIds = User::where('organization_id', $user->organization_id)
                ->whereIn('manager_id', array_merge([$user->id], $teamLeaderIds))
                ->pluck('id')
                ->toArray();

            $allSubordinateIds = array_merge($teamLeaderIds, $teamEmpIds);

            $query->where(function ($q) use ($user, $allSubordinateIds) {
                $q->where('assigned_to', $user->id)
                  ->orWhere('assigner_id', $user->id)
                  ->orWhereIn('assigned_to', $allSubordinateIds);
            });
        } elseif ($role === 'hr') {
            // HR sees tasks assigned to HR by Admin + tasks created by HR + tasks of HR employees
            $hrEmpIds = User::where('organization_id', $user->organization_id)
                ->where(function ($q) use ($user) {
                    $q->where('manager_id', $user->id)
                      ->orWhere('department', $user->department);
                })
                ->pluck('id')
                ->toArray();

            $query->where(function ($q) use ($user, $hrEmpIds) {
                $q->where('assigned_to', $user->id)
                  ->orWhere('assigner_id', $user->id)
                  ->orWhereIn('assigned_to', $hrEmpIds);
            });
        }
        // Admin sees all organization tasks

        // Scope filter overrides
        $scope = $request->query('scope');
        if ($scope === 'assigned_to_me') {
            $query->where('assigned_to', $user->id);
        } elseif ($scope === 'assigned_by_me') {
            $query->where('assigner_id', $user->id);
        }

        // Calculate global metrics before applying UI filters
        $allScopedTasks = (clone $query)->get();
        $today = Carbon::today();
        foreach ($allScopedTasks as $t) {
            if ($t->due_date && Carbon::parse($t->due_date)->isBefore($today) && $t->status !== 'completed' && $t->status !== 'cancelled') {
                if ($t->status !== 'overdue') {
                    $t->status = 'overdue';
                    $t->save();

                    $assignee = User::find($t->assigned_to);
                    if ($assignee) {
                        NotificationService::create(
                            $t->organization_id,
                            $assignee->id,
                            'Task Overdue Warning',
                            "Your assigned task \"{$t->title}\" is overdue (Due: {$t->due_date}).",
                            'warning',
                            '/employee/tasks'
                        );

                        NotificationService::notifyManagementChain(
                            $assignee,
                            'Task Overdue Alert',
                            "Task \"{$t->title}\" assigned to {$assignee->name} is overdue (Due: {$t->due_date}).",
                            'warning',
                            '/admin/tasks'
                        );
                    }
                }
            }
        }

        $metrics = [
            'total' => $allScopedTasks->count(),
            'todo' => $allScopedTasks->where('status', 'todo')->count(),
            'in_progress' => $allScopedTasks->where('status', 'in_progress')->count(),
            'completed' => $allScopedTasks->where('status', 'completed')->count(),
            'overdue' => $allScopedTasks->where('status', 'overdue')->count(),
            'cancelled' => $allScopedTasks->where('status', 'cancelled')->count(),
            'completion_rate' => $allScopedTasks->count() > 0 ? round(($allScopedTasks->where('status', 'completed')->count() / max(1, $allScopedTasks->where('status', '!=', 'cancelled')->count())) * 100, 1) : 0,
        ];

        // Query Filters (apply to list only)
        if ($request->filled('status') && $request->status !== 'all') {
            $reqStatus = $request->status;
            $todayStr = $today->toDateString();

            if ($reqStatus === 'pending' || $reqStatus === 'todo') {
                $query->whereIn('status', ['todo', 'pending'])
                      ->where(function ($q) use ($todayStr) {
                          $q->whereNull('due_date')
                            ->orWhere('due_date', '>=', $todayStr);
                      });
            } elseif ($reqStatus === 'in_progress') {
                $query->where('status', 'in_progress')
                      ->where(function ($q) use ($todayStr) {
                          $q->whereNull('due_date')
                            ->orWhere('due_date', '>=', $todayStr);
                      });
            } elseif ($reqStatus === 'overdue') {
                $query->whereNotIn('status', ['completed', 'cancelled'])
                      ->whereNotNull('due_date')
                      ->where('due_date', '<', $todayStr);
            } else {
                $query->where('status', $reqStatus);
            }
        }

        if ($request->filled('priority') && $request->priority !== 'all') {
            $query->where('priority', $request->priority);
        }

        if ($request->filled('category') && $request->category !== 'all') {
            $query->where('category', $request->category);
        }

        if ($request->filled('assigned_to')) {
            $query->where('assigned_to', $request->assigned_to);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $tasks = $query->orderBy('created_at', 'desc')->get();
        foreach ($tasks as $t) {
            if ($t->due_date && Carbon::parse($t->due_date)->isBefore($today) && $t->status !== 'completed' && $t->status !== 'cancelled') {
                $t->status = 'overdue';
            }
        }

        return response()->json([
            'tasks' => $tasks,
            'metrics' => $metrics,
        ]);
    }

    /**
     * Create task with strict Hierarchy Validation
     */
    public function store(Request $request)
    {
        $actor = $request->user();
        $actorRole = $this->getRoleName($actor);

        if ($actorRole === 'employee') {
            return response()->json(['message' => 'Unauthorized: Employees cannot assign tasks.'], 403);
        }

        $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'assigned_to' => 'required|exists:users,id',
            'priority' => 'nullable|in:low,medium,high,urgent',
            'category' => 'nullable|string',
            'start_date' => 'nullable|date',
            'due_date' => 'nullable|date',
            'subtasks' => 'nullable|array',
            'notes' => 'nullable|string',
        ]);

        $targetUser = User::where('organization_id', $actor->organization_id)
            ->where('id', $request->assigned_to)
            ->first();

        if (!$targetUser) {
            return response()->json(['message' => 'Assigned user not found in organization.'], 404);
        }

        $targetRole = $this->getRoleName($targetUser);
        $authorizedAssigneeIds = $this->getAuthorizedAssigneeIds($actor);

        // Strict Hierarchy Verification Check
        if (!in_array($targetUser->id, $authorizedAssigneeIds)) {
            return response()->json([
                'message' => "Unauthorized Hierarchy Assignment: Role '{$actorRole}' cannot assign task to role '{$targetRole}' or user outside your management scope."
            ], 403);
        }

        $task = Task::create([
            'organization_id' => $actor->organization_id,
            'assigner_id' => $actor->id,
            'assigned_to' => $targetUser->id,
            'assigned_by_role' => $actorRole,
            'assigned_to_role' => $targetRole,
            'title' => $request->title,
            'description' => $request->description,
            'category' => $request->category ?? 'general',
            'priority' => $request->priority ?? 'medium',
            'status' => 'todo',
            'progress_percentage' => 0,
            'start_date' => $request->start_date ?? Carbon::today()->toDateString(),
            'due_date' => $request->due_date,
            'subtasks' => $request->subtasks ?? [],
            'notes' => $request->notes,
        ]);

        $task->load([
            'assigner:id,name,email,avatar,role_id',
            'assigner.role:id,name,display_name',
            'assignedTo:id,name,email,avatar,department,designation,role_id',
            'assignedTo.role:id,name,display_name'
        ]);

        NotificationService::create(
            $actor->organization_id,
            $targetUser->id,
            'New Task Assigned',
            "You have been assigned a new task: \"{$task->title}\" (Priority: {$task->priority}).",
            'info',
            '/employee/tasks'
        );

        NotificationService::notifyManagementChain(
            $targetUser,
            'New Task Created',
            "Task \"{$task->title}\" was assigned to {$targetUser->name} by {$actor->name}.",
            'info',
            '/admin/tasks'
        );

        return response()->json([
            'message' => 'Task created and assigned successfully',
            'task' => $task,
        ], 201);
    }

    /**
     * Show task details
     */
    public function show(Request $request, $id)
    {
        $user = $request->user();
        $task = Task::where('organization_id', $user->organization_id)
            ->where('id', $id)
            ->with([
                'assigner:id,name,email,avatar,role_id',
                'assigner.role:id,name,display_name',
                'assignedTo:id,name,email,avatar,department,designation,role_id',
                'assignedTo.role:id,name,display_name'
            ])
            ->first();

        if (!$task) {
            return response()->json(['message' => 'Task not found'], 404);
        }

        return response()->json(['task' => $task]);
    }

    /**
     * Update task status & progress
     */
    public function updateStatus(Request $request, $id)
    {
        $user = $request->user();
        $task = Task::where('organization_id', $user->organization_id)
            ->where('id', $id)
            ->first();

        if (!$task) {
            return response()->json(['message' => 'Task not found'], 404);
        }

        // Only the assigned employee can change task status (assigners can only view status)
        if ((int)$task->assigned_to !== (int)$user->id) {
            return response()->json([
                'message' => 'Only the assigned employee can update this task\'s status.'
            ], 403);
        }

        $request->validate([
            'status' => 'required|in:todo,pending,in_progress,under_review,completed,overdue,cancelled',
            'completion_notes' => 'nullable|string',
            'progress_percentage' => 'nullable|integer|min:0|max:100',
        ]);

        $statusInput = $request->status;
        if ($statusInput === 'pending') {
            $newStatus = 'todo';
        } elseif ($statusInput === 'under_review') {
            $newStatus = 'in_progress';
        } else {
            $newStatus = $statusInput;
        }

        // Phase progression ranks: todo (1) -> in_progress (2) -> completed (3)
        $phaseRanks = [
            'todo' => 1,
            'pending' => 1,
            'in_progress' => 2,
            'under_review' => 2,
            'completed' => 3,
            'overdue' => 2,
            'cancelled' => 3,
        ];

        $currentRank = $phaseRanks[$task->status] ?? 1;
        $targetRank = $phaseRanks[$newStatus] ?? 1;

        $isAdminOrAssigner = $user->id === $task->assigner_id || in_array($user->getCanonicalRole(), ['admin', 'hr']);

        // Non-admin assignee cannot manually move backward to a previous phase
        if (!$isAdminOrAssigner && $targetRank < $currentRank && $task->status !== 'overdue') {
            return response()->json([
                'message' => 'Status progression error: You cannot move a task backward to a previous phase.'
            ], 422);
        }

        $subtasks = $task->subtasks ?? [];
        if (is_array($subtasks) && count($subtasks) > 0 && $newStatus === 'completed') {
            $completedSubtasks = count(array_filter($subtasks, fn($s) => !empty($s['completed'])));
            if ($completedSubtasks < count($subtasks)) {
                return response()->json([
                    'message' => 'Cannot complete task: Please finish all checklist subtasks first.'
                ], 422);
            }
        }

        $task->status = $newStatus;

        if ($request->has('progress_percentage')) {
            $task->progress_percentage = $request->progress_percentage;
        }

        if ($request->status === 'in_progress' && $task->progress_percentage === 0) {
            $task->progress_percentage = 25;
        }

        if ($request->status === 'completed') {
            $task->progress_percentage = 100;
            $task->completed_at = Carbon::now();
            if ($request->filled('completion_notes')) {
                $task->completion_notes = $request->completion_notes;
            }

            if ($task->assigner_id && $task->assigner_id !== $user->id) {
                NotificationService::create(
                    $task->organization_id,
                    $task->assigner_id,
                    'Task Completed',
                    "{$user->name} has completed task: \"{$task->title}\".",
                    'success',
                    '/manager/tasks'
                );
            }

            NotificationService::notifyManagementChain(
                $user,
                'Task Completed',
                "Task \"{$task->title}\" has been completed by {$user->name}.",
                'success',
                '/admin/tasks'
            );
        } else {
            $task->completed_at = null;
        }

        $task->save();

        return response()->json([
            'message' => 'Task status updated successfully',
            'task' => $task->load([
                'assigner:id,name,email,avatar,role_id',
                'assigner.role:id,name,display_name',
                'assignedTo:id,name,email,avatar,department,designation,role_id',
                'assignedTo.role:id,name,display_name'
            ])
        ]);
    }

    /**
     * Toggle subtask completion status
     */
    public function toggleSubtask(Request $request, $id)
    {
        $user = $request->user();
        $task = Task::where('organization_id', $user->organization_id)
            ->where('id', $id)
            ->first();

        if (!$task) {
            return response()->json(['message' => 'Task not found'], 404);
        }

        // Only the assigned employee performing the task can update checklist subtasks
        if ((int)$task->assigned_to !== (int)$user->id) {
            return response()->json([
                'message' => 'Unauthorized: Only the assigned employee performing this task can update checklist subtasks.'
            ], 403);
        }

        $request->validate([
            'subtask_id' => 'required',
        ]);

        $subtaskId = $request->subtask_id;
        $subtasks = $task->subtasks ?? [];
        if (!is_array($subtasks)) {
            $subtasks = [];
        }

        $found = false;
        $completedCount = 0;

        foreach ($subtasks as &$subtask) {
            if (isset($subtask['id']) && (string)$subtask['id'] === (string)$subtaskId) {
                $subtask['completed'] = !($subtask['completed'] ?? false);
                $found = true;
            }
            if (!empty($subtask['completed'])) {
                $completedCount++;
            }
        }
        unset($subtask);

        if (!$found) {
            return response()->json(['message' => 'Subtask not found'], 404);
        }

        $task->subtasks = $subtasks;

        $totalSubtasks = count($subtasks);
        if ($totalSubtasks > 0) {
            $task->progress_percentage = (int) round(($completedCount / $totalSubtasks) * 100);
            if ($completedCount === $totalSubtasks) {
                $task->status = 'completed';
                $task->completed_at = Carbon::now();
            } else {
                // If subtask was pulled back / unchecked, task MUST NOT remain completed!
                if ($task->status === 'completed') {
                    $task->status = $completedCount > 0 ? 'in_progress' : 'todo';
                    $task->completed_at = null;
                } elseif ($task->status === 'todo' && $completedCount > 0) {
                    $task->status = 'in_progress';
                }
            }
        }

        $task->save();

        return response()->json([
            'message' => 'Subtask updated successfully',
            'task' => $task->load([
                'assigner:id,name,email,avatar,role_id',
                'assigner.role:id,name,display_name',
                'assignedTo:id,name,email,avatar,department,designation,role_id',
                'assignedTo.role:id,name,display_name'
            ])
        ]);
    }

    /**
     * Update task details (Admin, HR, Manager, Team Leader or Assigner)
     */
    public function update(Request $request, $id)
    {
        $user = $request->user();
        $role = $this->getRoleName($user);

        $task = Task::where('id', $id)->first();

        if (!$task) {
            return response()->json(['message' => 'Task not found'], 404);
        }

        if (in_array($role, ['admin', 'hr'])) {
            // Admin and HR have full access
        } elseif ($user->organization_id && $task->organization_id && (int)$task->organization_id !== (int)$user->organization_id) {
            return response()->json(['message' => 'Unauthorized: Task belongs to a different organization.'], 403);
        } elseif ((int)$task->assigner_id !== (int)$user->id && !in_array($role, ['manager', 'team_leader'])) {
            return response()->json(['message' => 'Unauthorized: Only the task creator or management can edit this task.'], 403);
        }

        $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'assigned_to' => 'nullable|exists:users,id',
            'priority' => 'nullable|in:low,medium,high,urgent',
            'category' => 'nullable|string',
            'start_date' => 'nullable|date',
            'due_date' => 'nullable|date',
            'subtasks' => 'nullable|array',
            'notes' => 'nullable|string',
        ]);

        if ($request->has('title')) $task->title = $request->title;
        if ($request->has('description')) $task->description = $request->description;
        if ($request->has('priority')) $task->priority = $request->priority;
        if ($request->has('category')) $task->category = $request->category;
        if ($request->has('start_date')) $task->start_date = $request->start_date;
        if ($request->has('due_date')) $task->due_date = $request->due_date;
        if ($request->has('subtasks')) $task->subtasks = $request->subtasks;
        if ($request->has('notes')) $task->notes = $request->notes;

        if ($request->filled('assigned_to') && (int)$request->assigned_to !== (int)$task->assigned_to) {
            $targetUser = User::find($request->assigned_to);
            if ($targetUser) {
                $task->assigned_to = $targetUser->id;
                $task->assigned_to_role = $this->getRoleName($targetUser);
            }
        }

        $task->save();

        return response()->json([
            'message' => 'Task updated successfully',
            'task' => $task->load([
                'assigner:id,name,email,avatar,role_id',
                'assigner.role:id,name,display_name',
                'assignedTo:id,name,email,avatar,department,designation,role_id',
                'assignedTo.role:id,name,display_name'
            ])
        ]);
    }

    /**
     * Delete / Cancel task
     */
    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        $role = $this->getRoleName($user);

        // Find task by ID
        $task = Task::where('id', $id)->first();

        if (!$task) {
            return response()->json(['message' => 'Task not found'], 404);
        }

        // Check organization isolation unless global admin
        if ($user->organization_id && $task->organization_id && (int)$task->organization_id !== (int)$user->organization_id && $role !== 'admin') {
            return response()->json(['message' => 'Unauthorized: Task belongs to a different organization.'], 403);
        }

        // Authorization check: Admin, HR, Manager, Team Leader, or the user who created/assigned it
        $isAuthorized = in_array($role, ['admin', 'hr', 'manager', 'team_leader'])
            || (int)$task->assigner_id === (int)$user->id
            || (int)$task->assigned_to === (int)$user->id;

        if (!$isAuthorized) {
            return response()->json(['message' => 'Unauthorized: You do not have permission to delete this task.'], 403);
        }

        $task->delete();

        return response()->json([
            'message' => 'Task deleted successfully',
            'task_id' => (int)$id,
        ]);
    }

    /**
     * Role-scoped employee performance metrics API
     */
    public function employeePerformance(Request $request)
    {
        $user = $request->user();
        $role = $this->getRoleName($user);

        if ($role === 'employee') {
            return response()->json(['message' => 'Employees cannot view task performance lists of others.'], 403);
        }

        $query = User::where('organization_id', $user->organization_id)
            ->where('status', 'active')
            ->select('id', 'name', 'email', 'employee_code', 'department', 'designation', 'avatar', 'role_id')
            ->with('role:id,name,display_name');

        if ($role === 'team_leader') {
            // Team Leader sees only team employees under them
            $query->where('manager_id', $user->id);
        } elseif ($role === 'manager') {
            // Manager sees Team Leaders and employees in manager's teams
            $tlIds = User::where('organization_id', $user->organization_id)
                ->where('manager_id', $user->id)
                ->pluck('id')
                ->toArray();
            $query->where(function ($q) use ($user, $tlIds) {
                $q->where('manager_id', $user->id)
                  ->orWhereIn('manager_id', $tlIds);
            });
        } elseif ($role === 'hr') {
            // HR sees employees under HR scope
            $query->where(function ($q) use ($user) {
                $q->where('manager_id', $user->id)
                  ->orWhere('department', $user->department)
                  ->orWhereNull('manager_id');
            });
        }
        // Admin sees all organization employees

        $employees = $query->orderBy('name', 'asc')->get();
        $today = Carbon::today();

        $performances = $employees->map(function ($emp) use ($today) {
            $tasks = Task::where('assigned_to', $emp->id)->get();
            $total = $tasks->count();
            $completed = $tasks->where('status', 'completed')->count();
            $inProgress = $tasks->where('status', 'in_progress')->count();
            $todo = $tasks->where('status', 'todo')->count();
            $cancelled = $tasks->where('status', 'cancelled')->count();

            $overdue = $tasks->filter(function ($t) use ($today) {
                return ($t->status === 'overdue') || ($t->due_date && Carbon::parse($t->due_date)->isBefore($today) && $t->status !== 'completed' && $t->status !== 'cancelled');
            })->count();

            $nonCancelledTotal = $total - $cancelled;
            $totalProgressSum = $tasks->where('status', '!=', 'cancelled')->sum(function ($t) {
                if ($t->status === 'completed') return 100;
                if ($t->status === 'in_progress') return max(25, $t->progress_percentage ?? 25);
                return 0;
            });
            $completionRate = $nonCancelledTotal > 0 ? round(($totalProgressSum / $nonCancelledTotal), 1) : 0;

            // On-time completion count
            $onTimeCompleted = $tasks->filter(function ($t) {
                return $t->status === 'completed' && $t->completed_at && $t->due_date && Carbon::parse($t->completed_at)->startOfDay()->lte(Carbon::parse($t->due_date)->startOfDay());
            })->count();

            $onTimeRate = $completed > 0 ? round(($onTimeCompleted / $completed) * 100, 1) : 100;

            if ($nonCancelledTotal === 0) {
                $rating = 'No Assigned Tasks';
                $badge = 'neutral';
                $score = 0;
            } else {
                $score = round(($completionRate * 0.7) + ($onTimeRate * 0.3), 1);
                if ($score >= 90 && $overdue === 0) {
                    $rating = 'Top Performer';
                    $badge = 'emerald';
                } elseif ($score >= 75) {
                    $rating = 'High Performer';
                    $badge = 'blue';
                } elseif ($score >= 50) {
                    $rating = 'Average Performer';
                    $badge = 'amber';
                } else {
                    $rating = 'Needs Attention';
                    $badge = 'rose';
                }
            }

            return [
                'employee_id' => $emp->id,
                'name' => $emp->name,
                'email' => $emp->email,
                'employee_code' => $emp->employee_code,
                'department' => $emp->department || 'General',
                'designation' => $emp->designation || 'Staff',
                'avatar' => $emp->avatar,
                'role' => $emp->role->display_name ?? 'Employee',
                'total_tasks' => $total,
                'completed_tasks' => $completed,
                'in_progress_tasks' => $inProgress,
                'todo_tasks' => $todo,
                'pending_tasks' => $todo,
                'overdue_tasks' => $overdue,
                'cancelled_tasks' => $cancelled,
                'completion_rate' => $completionRate,
                'ontime_rate' => $onTimeRate,
                'performance_score' => $score,
                'rating' => $rating,
                'rating_badge' => $badge,
            ];
        })->sortByDesc('completion_rate')->values();

        $totalOrgTasks = Task::where('organization_id', $user->organization_id)->count();
        $totalCompletedTasks = Task::where('organization_id', $user->organization_id)->where('status', 'completed')->count();
        $overallCompletionRate = $totalOrgTasks > 0 ? round(($totalCompletedTasks / max(1, $totalOrgTasks)) * 100, 1) : 0;

        $summary = [
            'overall_completion_rate' => $overallCompletionRate,
            'total_organization_tasks' => $totalOrgTasks,
            'total_completed_tasks' => $totalCompletedTasks,
            'total_employees' => $performances->count(),
        ];

        return response()->json([
            'performances' => $performances,
            'summary' => $summary,
        ]);
    }

    /**
     * Dashboard Summary Stats API (Single Source of Truth)
     */
    public function dashboardStats(Request $request)
    {
        $user = $request->user();
        $role = $this->getRoleName($user);

        // Fetch task metrics using index logic
        $indexRes = $this->index($request)->getData(true);
        $taskMetrics = $indexRes['metrics'] ?? [];

        // Count employees & hierarchy members per role scope
        $orgId = $user->organization_id;
        $totalEmp = 0;
        $totalHR = 0;
        $totalManagers = 0;
        $totalTeamLeaders = 0;

        if ($role === 'admin') {
            $totalEmp = User::where('organization_id', $orgId)->where('status', 'active')->count();
            $totalHR = User::where('organization_id', $orgId)->whereHas('role', fn($q) => $q->where('name', 'hr'))->count();
            $totalManagers = User::where('organization_id', $orgId)->whereHas('role', fn($q) => $q->whereIn('name', ['manager', 'company_manager']))->count();
            $totalTeamLeaders = User::where('organization_id', $orgId)->whereHas('role', fn($q) => $q->whereIn('name', ['team_leader', 'tl']))->count();
        } elseif ($role === 'hr') {
            $totalEmp = User::where('organization_id', $orgId)->where('status', 'active')->where(function ($q) use ($user) {
                $q->where('manager_id', $user->id)->orWhere('department', $user->department);
            })->count();
        } elseif ($role === 'manager') {
            $totalTeamLeaders = User::where('organization_id', $orgId)->where('manager_id', $user->id)->whereHas('role', fn($q) => $q->whereIn('name', ['team_leader', 'tl']))->count();
            $tlIds = User::where('organization_id', $orgId)->where('manager_id', $user->id)->pluck('id')->toArray();
            $totalEmp = User::where('organization_id', $orgId)->whereIn('manager_id', array_merge([$user->id], $tlIds))->count();
        } elseif ($role === 'team_leader') {
            $totalEmp = User::where('organization_id', $orgId)->where('manager_id', $user->id)->count();
        }

        return response()->json([
            'role' => $role,
            'counts' => [
                'total_employees' => $totalEmp,
                'total_hr' => $totalHR,
                'total_managers' => $totalManagers,
                'total_team_leaders' => $totalTeamLeaders,
            ],
            'tasks' => $taskMetrics,
            'recent_tasks' => array_slice($indexRes['tasks'] ?? [], 0, 5),
        ]);
    }

    private function seedInitialTasks($orgId)
    {
        $admin = User::where('organization_id', $orgId)->whereHas('role', function($q) { $q->where('name', 'admin'); })->first()
            ?? User::where('organization_id', $orgId)->first();
        if (!$admin) return;

        $hr = User::where('organization_id', $orgId)->whereHas('role', function($q) { $q->where('name', 'hr'); })->first()
            ?? $admin;
        $employees = User::where('organization_id', $orgId)->where('id', '!=', $admin->id)->get();
        $emp1 = $employees->first() ?? $admin;
        $emp2 = $employees->skip(1)->first() ?? $emp1;

        Task::create([
            'organization_id' => $orgId,
            'assigner_id' => $admin->id,
            'assigned_to' => $emp1->id,
            'assigned_by_role' => 'admin',
            'assigned_to_role' => 'employee',
            'title' => 'Deliver HRMS Core Module Integration',
            'description' => 'Complete single master employee record integration, attendance sync, and role permissions.',
            'category' => 'project',
            'priority' => 'high',
            'status' => 'in_progress',
            'progress_percentage' => 75,
            'start_date' => Carbon::today()->subDays(5)->toDateString(),
            'due_date' => Carbon::today()->addDays(5)->toDateString(),
            'subtasks' => [
                ['id' => '1', 'text' => 'Master User Sync', 'title' => 'Master User Sync', 'completed' => true],
                ['id' => '2', 'text' => 'Role Scoping Audit', 'title' => 'Role Scoping Audit', 'completed' => true],
                ['id' => '3', 'text' => 'Final QA Testing', 'title' => 'Final QA Testing', 'completed' => false],
            ],
            'notes' => 'High priority operational task for Q3 milestone.',
        ]);

        Task::create([
            'organization_id' => $orgId,
            'assigner_id' => $admin->id,
            'assigned_to' => $hr->id,
            'assigned_by_role' => 'admin',
            'assigned_to_role' => 'hr',
            'title' => 'Quarterly HR Performance & Policy Review',
            'description' => 'Review Q3 employee goals, attendance anomalies, and team appraisal cycles.',
            'category' => 'review',
            'priority' => 'urgent',
            'status' => 'todo',
            'progress_percentage' => 0,
            'start_date' => Carbon::today()->toDateString(),
            'due_date' => Carbon::today()->addDays(3)->toDateString(),
            'subtasks' => [
                ['id' => '1', 'text' => 'Compile Attrition Risk Summary', 'title' => 'Compile Attrition Risk Summary', 'completed' => false],
                ['id' => '2', 'text' => 'Verify Department Manager Feedback', 'title' => 'Verify Department Manager Feedback', 'completed' => false],
            ],
            'notes' => 'Requires executive board sign-off.',
        ]);

        Task::create([
            'organization_id' => $orgId,
            'assigner_id' => $hr->id,
            'assigned_to' => $emp2->id,
            'assigned_by_role' => 'hr',
            'assigned_to_role' => 'employee',
            'title' => 'Update Employee Tax Declarations',
            'description' => 'Verify investment proofs, PAN details, and tax regime preferences for FY 2026-27.',
            'category' => 'compliance',
            'priority' => 'medium',
            'status' => 'completed',
            'progress_percentage' => 100,
            'start_date' => Carbon::today()->subDays(10)->toDateString(),
            'due_date' => Carbon::today()->subDays(2)->toDateString(),
            'subtasks' => [
                ['id' => '1', 'text' => 'Upload Rent Receipts', 'title' => 'Upload Rent Receipts', 'completed' => true],
                ['id' => '2', 'text' => 'Verify 80C Investment Proofs', 'title' => 'Verify 80C Investment Proofs', 'completed' => true],
            ],
            'notes' => 'Verified and approved by HR Compliance team.',
        ]);
    }
}
