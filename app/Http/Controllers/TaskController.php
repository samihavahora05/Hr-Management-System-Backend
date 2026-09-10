<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\TaskSubmission;
use App\Models\TaskSubmissionFile;
use App\Models\TaskActivity;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\TaskPerformanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Carbon\Carbon;

class TaskController extends Controller
{
    /**
     * Helper to guarantee task edit tracking & verification columns exist in SQLite database
     */
    private function ensureSchemaIntegrity(): void
    {
        try {
            if (!\Illuminate\Support\Facades\Schema::hasColumn('tasks', 'maximum_marks')) {
                \Illuminate\Support\Facades\Schema::table('tasks', function (\Illuminate\Database\Schema\Blueprint $table) {
                    $table->integer('maximum_marks')->default(100);
                    $table->integer('marks_awarded')->nullable();
                    $table->timestamp('started_at')->nullable();
                    $table->unsignedBigInteger('started_by')->nullable();
                    $table->timestamp('submitted_at')->nullable();
                    $table->timestamp('reviewed_at')->nullable();
                    $table->unsignedBigInteger('reviewed_by')->nullable();
                    $table->text('admin_feedback')->nullable();
                });
            }
            if (!\Illuminate\Support\Facades\Schema::hasColumn('tasks', 'last_edited_by')) {
                \Illuminate\Support\Facades\Schema::table('tasks', function (\Illuminate\Database\Schema\Blueprint $table) {
                    $table->unsignedBigInteger('last_edited_by')->nullable();
                    $table->timestamp('last_edited_at')->nullable();
                    $table->text('last_edit_summary')->nullable();
                    $table->json('edit_history')->nullable();
                });
            }
            if (!\Illuminate\Support\Facades\Schema::hasTable('task_submissions')) {
                \Illuminate\Support\Facades\Schema::create('task_submissions', function (\Illuminate\Database\Schema\Blueprint $table) {
                    $table->id();
                    $table->unsignedBigInteger('task_id');
                    $table->unsignedBigInteger('employee_id');
                    $table->integer('submission_number')->default(1);
                    $table->text('completion_note');
                    $table->text('what_was_completed')->nullable();
                    $table->text('employee_comment')->nullable();
                    $table->string('status')->default('submitted');
                    $table->timestamp('submitted_at')->useCurrent();
                    $table->timestamp('reviewed_at')->nullable();
                    $table->unsignedBigInteger('reviewed_by')->nullable();
                    $table->text('admin_feedback')->nullable();
                    $table->integer('marks_awarded')->nullable();
                    $table->integer('maximum_marks')->default(100);
                    $table->timestamps();
                });
            }
            if (!\Illuminate\Support\Facades\Schema::hasTable('task_submission_files')) {
                \Illuminate\Support\Facades\Schema::create('task_submission_files', function (\Illuminate\Database\Schema\Blueprint $table) {
                    $table->id();
                    $table->unsignedBigInteger('submission_id');
                    $table->unsignedBigInteger('task_id');
                    $table->unsignedBigInteger('user_id');
                    $table->string('original_name');
                    $table->string('file_path');
                    $table->string('file_type')->nullable();
                    $table->unsignedBigInteger('file_size')->default(0);
                    $table->timestamps();
                });
            }
            if (!\Illuminate\Support\Facades\Schema::hasTable('task_activities')) {
                \Illuminate\Support\Facades\Schema::create('task_activities', function (\Illuminate\Database\Schema\Blueprint $table) {
                    $table->id();
                    $table->unsignedBigInteger('task_id');
                    $table->unsignedBigInteger('submission_id')->nullable();
                    $table->string('event_type');
                    $table->unsignedBigInteger('performed_by')->nullable();
                    $table->string('performed_by_role')->nullable();
                    $table->string('previous_status')->nullable();
                    $table->string('new_status')->nullable();
                    $table->text('description');
                    $table->json('metadata')->nullable();
                    $table->timestamps();
                });
            }
        } catch (\Throwable $e) {
            // Ignore if schema table already locked or altered
        }
    }

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
            return User::where('organization_id', $actor->organization_id)
                ->where('status', 'active')
                ->pluck('id')
                ->toArray();
        }

        if ($role === 'manager') {
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
        $this->ensureSchemaIntegrity();
        $user = $request->user();
        $role = $this->getRoleName($user);

        $query = Task::where('organization_id', $user->organization_id)
            ->with([
                'assigner:id,name,email,avatar,role_id',
                'assigner.role:id,name,display_name',
                'assignedTo:id,name,email,avatar,department,designation,role_id',
                'assignedTo.role:id,name,display_name',
                'starter:id,name,email',
                'reviewer:id,name,email',
                'lastEditor:id,name,email,avatar,role_id',
                'lastEditor.role:id,name,display_name',
                'latestSubmission.files',
                'latestSubmission.reviewer:id,name',
            ]);

        // Strict Role Scoping
        if ($role === 'employee') {
            $query->where('assigned_to', $user->id);
        } elseif ($role === 'team_leader') {
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

        // Scope filter overrides
        $scope = $request->query('scope');
        if ($scope === 'assigned_to_me') {
            $query->where('assigned_to', $user->id);
        } elseif ($scope === 'assigned_by_me') {
            $query->where('assigner_id', $user->id);
        }

        // Calculate global metrics before applying UI list filters
        $allScopedTasks = (clone $query)->get();
        $today = Carbon::today();

        // Check overdue status
        foreach ($allScopedTasks as $t) {
            if ($t->due_date && Carbon::parse($t->due_date)->isBefore($today) && !in_array($t->status, ['approved', 'completed', 'cancelled'])) {
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
                    }
                }
            }
        }

        $approvedTasks = $allScopedTasks->filter(fn($t) => in_array($t->status, ['approved', 'completed']));
        $approvedWithMarks = $approvedTasks->filter(fn($t) => $t->marks_awarded !== null);

        $totalEarnedMarks = (int) $approvedWithMarks->sum('marks_awarded');
        $totalPossibleMarks = (int) $approvedWithMarks->sum(fn($t) => max(1, $t->maximum_marks ?? 100));
        $performancePercentage = $totalPossibleMarks > 0
            ? round(($totalEarnedMarks / $totalPossibleMarks) * 100, 1)
            : ($approvedTasks->count() > 0 ? 100.0 : 0.0);

        $metrics = [
            'total' => $allScopedTasks->count(),
            'todo' => $allScopedTasks->filter(fn($t) => in_array($t->status, ['todo', 'assigned']))->count(),
            'in_progress' => $allScopedTasks->where('status', 'in_progress')->count(),
            'submitted_for_review' => $allScopedTasks->where('status', 'submitted_for_review')->count(),
            'needs_revision' => $allScopedTasks->where('status', 'needs_revision')->count(),
            'approved' => $approvedTasks->count(),
            'completed' => $approvedTasks->count(),
            'overdue' => $allScopedTasks->where('status', 'overdue')->count(),
            'cancelled' => $allScopedTasks->where('status', 'cancelled')->count(),
            'total_earned_marks' => $totalEarnedMarks,
            'total_possible_marks' => $totalPossibleMarks,
            'performance_percentage' => $performancePercentage,
            'completion_rate' => $allScopedTasks->count() > 0 ? round(($approvedTasks->count() / max(1, $allScopedTasks->where('status', '!=', 'cancelled')->count())) * 100, 1) : 0,
        ];

        // Query Filters (apply to list only)
        if ($request->filled('status') && $request->status !== 'all') {
            $reqStatus = $request->status;
            $todayStr = $today->toDateString();

            if ($reqStatus === 'pending' || $reqStatus === 'todo' || $reqStatus === 'assigned') {
                $query->whereIn('status', ['todo', 'pending', 'assigned'])
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
            } elseif ($reqStatus === 'submitted_for_review') {
                $query->where('status', 'submitted_for_review');
            } elseif ($reqStatus === 'needs_revision') {
                $query->where('status', 'needs_revision');
            } elseif ($reqStatus === 'approved' || $reqStatus === 'completed') {
                $query->whereIn('status', ['approved', 'completed']);
            } elseif ($reqStatus === 'overdue') {
                $query->whereNotIn('status', ['approved', 'completed', 'cancelled'])
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
            if ($t->due_date && Carbon::parse($t->due_date)->isBefore($today) && !in_array($t->status, ['approved', 'completed', 'cancelled'])) {
                $t->status = 'overdue';
            }
        }

        return response()->json([
            'tasks' => $tasks,
            'metrics' => $metrics,
        ]);
    }

    /**
     * Create task with Maximum Marks and strict Hierarchy Validation
     */
    public function store(Request $request)
    {
        $this->ensureSchemaIntegrity();
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
            'maximum_marks' => 'nullable|integer|min:1|max:1000',
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

        if (!in_array($targetUser->id, $authorizedAssigneeIds)) {
            return response()->json([
                'message' => "Unauthorized Hierarchy Assignment: Role '{$actorRole}' cannot assign task to role '{$targetRole}' or user outside your management scope."
            ], 403);
        }

        $maxMarks = $request->filled('maximum_marks') ? (int)$request->maximum_marks : 100;

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
            'maximum_marks' => $maxMarks,
            'start_date' => $request->start_date ?? Carbon::today()->toDateString(),
            'due_date' => $request->due_date,
            'subtasks' => $request->subtasks ?? [],
            'notes' => $request->notes,
        ]);

        // Permanent Audit Log
        TaskActivity::log(
            $task->id,
            'task_created',
            "Task \"{$task->title}\" created with maximum marks {$maxMarks} and assigned to {$targetUser->name}.",
            $actor,
            null,
            'todo',
            null,
            ['maximum_marks' => $maxMarks, 'priority' => $task->priority, 'due_date' => $task->due_date]
        );

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
            "You have been assigned task \"{$task->title}\" (Max Marks: {$maxMarks}, Priority: {$task->priority}).",
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
     * Show task details with full submissions and activity history
     */
    public function show(Request $request, $id)
    {
        $this->ensureSchemaIntegrity();
        $user = $request->user();
        $role = $this->getRoleName($user);

        $task = Task::where('organization_id', $user->organization_id)
            ->where('id', $id)
            ->with([
                'assigner:id,name,email,avatar,role_id',
                'assigner.role:id,name,display_name',
                'assignedTo:id,name,email,avatar,department,designation,role_id',
                'assignedTo.role:id,name,display_name',
                'starter:id,name,email',
                'reviewer:id,name,email',
                'lastEditor:id,name,email,avatar,role_id',
                'lastEditor.role:id,name,display_name',
                'submissions.files',
                'submissions.reviewer:id,name',
                'submissions.employee:id,name',
                'activities.performer:id,name,role_id',
                'activities.performer.role:id,name,display_name',
            ])
            ->first();

        if (!$task) {
            return response()->json(['message' => 'Task not found'], 404);
        }

        // Authorization check
        $isAuthorized = false;
        if (in_array($role, ['admin', 'hr'])) {
            $isAuthorized = true;
        } elseif ((int)$task->assigned_to === (int)$user->id || (int)$task->assigner_id === (int)$user->id) {
            $isAuthorized = true;
        } elseif ($role === 'team_leader') {
            $teamEmpIds = User::where('organization_id', $user->organization_id)
                ->where('manager_id', $user->id)
                ->pluck('id')
                ->toArray();
            $isAuthorized = in_array($task->assigned_to, $teamEmpIds);
        } elseif ($role === 'manager') {
            $teamLeaderIds = User::where('organization_id', $user->organization_id)
                ->where('manager_id', $user->id)
                ->pluck('id')
                ->toArray();
            $teamEmpIds = User::where('organization_id', $user->organization_id)
                ->whereIn('manager_id', array_merge([$user->id], $teamLeaderIds))
                ->pluck('id')
                ->toArray();
            $isAuthorized = in_array($task->assigned_to, array_merge($teamLeaderIds, $teamEmpIds));
        }

        if (!$isAuthorized) {
            return response()->json(['message' => 'Unauthorized: You do not have permission to view this task.'], 403);
        }

        return response()->json(['task' => $task]);
    }

    /**
     * Employee starts an assigned task: ASSIGNED -> IN_PROGRESS
     */
    public function start(Request $request, $id)
    {
        $this->ensureSchemaIntegrity();
        $user = $request->user();
        $role = $this->getRoleName($user);

        $task = Task::where('organization_id', $user->organization_id)
            ->where('id', $id)
            ->first();

        if (!$task) {
            return response()->json(['message' => 'Task not found'], 404);
        }

        if ((int)$task->assigned_to !== (int)$user->id && !in_array($role, ['admin', 'hr'])) {
            return response()->json(['message' => 'Only the assigned employee can start this task.'], 403);
        }

        if (in_array($task->status, ['in_progress', 'submitted_for_review', 'approved', 'completed'])) {
            return response()->json([
                'message' => "Task is already {$task->status}.",
                'task' => $task
            ], 200);
        }

        $prevStatus = $task->status;
        $task->status = 'in_progress';
        $task->started_at = Carbon::now();
        $task->started_by = $user->id;
        if ($task->progress_percentage === 0) {
            $task->progress_percentage = 25;
        }
        $task->save();

        // Audit Log
        TaskActivity::log(
            $task->id,
            'task_started',
            "{$user->name} started working on task \"{$task->title}\".",
            $user,
            $prevStatus,
            'in_progress'
        );

        return response()->json([
            'message' => 'Task started successfully!',
            'task' => $task->fresh([
                'assigner:id,name,email,avatar,role_id',
                'assigner.role:id,name,display_name',
                'assignedTo:id,name,email,avatar,department,designation,role_id',
                'assignedTo.role:id,name,display_name',
                'starter:id,name,email',
            ])
        ]);
    }

    /**
     * Employee submits task with proof files & completion notes: -> SUBMITTED_FOR_REVIEW
     */
    public function submit(Request $request, $id)
    {
        $this->ensureSchemaIntegrity();
        $user = $request->user();
        $role = $this->getRoleName($user);

        $task = Task::where('organization_id', $user->organization_id)
            ->where('id', $id)
            ->first();

        if (!$task) {
            return response()->json(['message' => 'Task not found'], 404);
        }

        if ((int)$task->assigned_to !== (int)$user->id && !in_array($role, ['admin', 'hr'])) {
            return response()->json(['message' => 'Only the assigned employee can submit this task for review.'], 403);
        }

        if (in_array($task->status, ['approved', 'completed'])) {
            return response()->json(['message' => 'This task has already been approved and completed.'], 422);
        }

        $request->validate([
            'completion_note' => 'required|string|min:5',
            'what_was_completed' => 'nullable|string',
            'employee_comment' => 'nullable|string',
            'proof_files' => 'required|array|min:1',
            'proof_files.*' => 'file|mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,jpg,jpeg,png,webp,zip|max:25600', // 25MB max per file
        ], [
            'proof_files.required' => 'At least one proof or deliverable file must be attached.',
            'proof_files.min' => 'At least one proof or deliverable file must be attached.',
            'completion_note.required' => 'Please provide a completion note explaining the work done.',
        ]);

        $prevStatus = $task->status;
        $submissionCount = TaskSubmission::where('task_id', $task->id)->count() + 1;

        $submission = TaskSubmission::create([
            'task_id' => $task->id,
            'employee_id' => $user->id,
            'submission_number' => $submissionCount,
            'completion_note' => $request->completion_note,
            'what_was_completed' => $request->what_was_completed,
            'employee_comment' => $request->employee_comment,
            'status' => 'submitted',
            'submitted_at' => Carbon::now(),
            'maximum_marks' => $task->maximum_marks ?? 100,
        ]);

        $uploadedFileNames = [];
        if ($request->hasFile('proof_files')) {
            foreach ($request->file('proof_files') as $file) {
                $origName = $file->getClientOriginalName();
                $ext = $file->getClientOriginalExtension();
                $safeName = Str::uuid()->toString() . '.' . $ext;
                $storedPath = $file->storeAs("tasks/{$task->organization_id}/{$task->id}", $safeName, 'local');

                TaskSubmissionFile::create([
                    'submission_id' => $submission->id,
                    'task_id' => $task->id,
                    'user_id' => $user->id,
                    'original_name' => $origName,
                    'file_path' => $storedPath,
                    'file_type' => $file->getClientMimeType() ?: $ext,
                    'file_size' => $file->getSize(),
                ]);

                $uploadedFileNames[] = $origName;
            }
        }

        // Update task record
        $task->status = 'submitted_for_review';
        $task->progress_percentage = 90;
        $task->submitted_at = Carbon::now();
        $task->completion_notes = $request->completion_note;
        $task->save();

        // Audit Trail Logs
        TaskActivity::log(
            $task->id,
            'task_submitted',
            "{$user->name} submitted task (Submission #{$submissionCount}) for Admin Review.",
            $user,
            $prevStatus,
            'submitted_for_review',
            $submission->id,
            ['submission_number' => $submissionCount, 'files' => $uploadedFileNames]
        );

        if (count($uploadedFileNames) > 0) {
            TaskActivity::log(
                $task->id,
                'proof_uploaded',
                "Proof file(s) attached: " . implode(', ', $uploadedFileNames),
                $user,
                null,
                null,
                $submission->id
            );
        }

        // Dispatch Notification to Management
        NotificationService::notifyRoles(
            $task->organization_id,
            ['admin', 'hr', 'manager'],
            'Task Submitted for Review',
            "{$user->name} submitted \"{$task->title}\" (Submission #{$submissionCount}) with " . count($uploadedFileNames) . " proof file(s). Review required.",
            'info',
            '/admin/tasks'
        );

        return response()->json([
            'message' => 'Task successfully submitted for admin review!',
            'submission' => $submission->load('files'),
            'task' => $task->fresh([
                'assigner:id,name,email,avatar,role_id',
                'assigner.role:id,name,display_name',
                'assignedTo:id,name,email,avatar,department,designation,role_id',
                'assignedTo.role:id,name,display_name',
                'latestSubmission.files',
            ])
        ], 201);
    }

    /**
     * Admin/Management Manual Review & Marks Evaluation
     */
    public function review(Request $request, $id)
    {
        $this->ensureSchemaIntegrity();
        $actor = $request->user();
        $role = $this->getRoleName($actor);

        if (!in_array($role, ['admin', 'hr', 'manager', 'team_leader'])) {
            return response()->json(['message' => 'Unauthorized: Only management can review and award marks for tasks.'], 403);
        }

        $task = Task::where('organization_id', $actor->organization_id)
            ->where('id', $id)
            ->first();

        if (!$task) {
            return response()->json(['message' => 'Task not found'], 404);
        }

        $request->validate([
            'action' => 'required|in:approve,request_revision',
            'marks_awarded' => 'required_if:action,approve|nullable|integer|min:0',
            'admin_feedback' => 'required_if:action,request_revision|nullable|string',
        ]);

        $maxMarks = $task->maximum_marks ?: 100;
        $prevStatus = $task->status;
        $latestSubmission = TaskSubmission::where('task_id', $task->id)->orderBy('submission_number', 'desc')->first();

        if ($request->action === 'approve') {
            $marksAwarded = (int)$request->marks_awarded;

            if ($marksAwarded < 0 || $marksAwarded > $maxMarks) {
                return response()->json([
                    'message' => "Invalid marks: Marks awarded must be between 0 and maximum marks ({$maxMarks})."
                ], 422);
            }

            $task->status = 'approved';
            $task->marks_awarded = $marksAwarded;
            $task->progress_percentage = 100;
            $task->reviewed_by = $actor->id;
            $task->reviewed_at = Carbon::now();
            $task->completed_at = Carbon::now();
            $task->admin_feedback = $request->admin_feedback;
            $task->save();

            if ($latestSubmission) {
                $latestSubmission->status = 'approved';
                $latestSubmission->marks_awarded = $marksAwarded;
                $latestSubmission->reviewed_by = $actor->id;
                $latestSubmission->reviewed_at = Carbon::now();
                $latestSubmission->admin_feedback = $request->admin_feedback;
                $latestSubmission->save();
            }

            // Audit Trail
            TaskActivity::log(
                $task->id,
                'task_approved',
                "Task approved by {$actor->name} ({$role}).",
                $actor,
                $prevStatus,
                'approved',
                $latestSubmission?->id
            );

            TaskActivity::log(
                $task->id,
                'marks_awarded',
                "{$actor->name} awarded {$marksAwarded}/{$maxMarks} marks (Score: " . round(($marksAwarded / $maxMarks) * 100, 1) . "%). Feedback: " . ($request->admin_feedback ?: 'Approved with verified proof.'),
                $actor,
                null,
                null,
                $latestSubmission?->id,
                ['marks_awarded' => $marksAwarded, 'maximum_marks' => $maxMarks]
            );

            // Notify Employee
            $assignee = User::find($task->assigned_to);
            if ($assignee) {
                NotificationService::create(
                    $task->organization_id,
                    $assignee->id,
                    'Task Approved & Scored',
                    "Your task \"{$task->title}\" was approved by Admin. You received {$marksAwarded}/{$maxMarks} marks.",
                    'success',
                    '/employee/tasks'
                );
            }

            return response()->json([
                'message' => "Task successfully approved with {$marksAwarded}/{$maxMarks} marks awarded!",
                'task' => $task->fresh([
                    'assigner:id,name,email,avatar,role_id',
                    'assigner.role:id,name,display_name',
                    'assignedTo:id,name,email,avatar,department,designation,role_id',
                    'assignedTo.role:id,name,display_name',
                    'reviewer:id,name,email',
                    'latestSubmission.files',
                ])
            ]);
        }

        if ($request->action === 'request_revision') {
            if (empty(trim($request->admin_feedback))) {
                return response()->json([
                    'message' => 'Please provide specific feedback/reason explaining what revision is required.'
                ], 422);
            }

            $task->status = 'needs_revision';
            $task->reviewed_by = $actor->id;
            $task->reviewed_at = Carbon::now();
            $task->admin_feedback = $request->admin_feedback;
            $task->save();

            if ($latestSubmission) {
                $latestSubmission->status = 'needs_revision';
                $latestSubmission->reviewed_by = $actor->id;
                $latestSubmission->reviewed_at = Carbon::now();
                $latestSubmission->admin_feedback = $request->admin_feedback;
                $latestSubmission->save();
            }

            // Audit Trail
            TaskActivity::log(
                $task->id,
                'revision_requested',
                "Revision requested by {$actor->name}. Reason: {$request->admin_feedback}",
                $actor,
                $prevStatus,
                'needs_revision',
                $latestSubmission?->id,
                ['feedback' => $request->admin_feedback]
            );

            // Notify Employee
            $assignee = User::find($task->assigned_to);
            if ($assignee) {
                NotificationService::create(
                    $task->organization_id,
                    $assignee->id,
                    'Task Revision Requested',
                    "Admin requested revision for task \"{$task->title}\": {$request->admin_feedback}",
                    'warning',
                    '/employee/tasks'
                );
            }

            return response()->json([
                'message' => 'Revision requested and employee notified successfully.',
                'task' => $task->fresh([
                    'assigner:id,name,email,avatar,role_id',
                    'assigner.role:id,name,display_name',
                    'assignedTo:id,name,email,avatar,department,designation,role_id',
                    'assignedTo.role:id,name,display_name',
                    'reviewer:id,name,email',
                    'latestSubmission.files',
                ])
            ]);
        }
    }

    /**
     * Admin modifies awarded marks with permanent audit history
     */
    public function updateMarks(Request $request, $id)
    {
        $this->ensureSchemaIntegrity();
        $actor = $request->user();
        $role = $this->getRoleName($actor);

        if (!in_array($role, ['admin', 'hr', 'manager'])) {
            return response()->json(['message' => 'Unauthorized: Only management can update awarded marks.'], 403);
        }

        $task = Task::where('organization_id', $actor->organization_id)
            ->where('id', $id)
            ->first();

        if (!$task) {
            return response()->json(['message' => 'Task not found'], 404);
        }

        $maxMarks = $task->maximum_marks ?: 100;

        $request->validate([
            'marks_awarded' => 'required|integer|min:0|max:' . $maxMarks,
            'reason' => 'nullable|string',
        ]);

        $prevMarks = $task->marks_awarded;
        $newMarks = (int)$request->marks_awarded;

        $task->marks_awarded = $newMarks;
        $task->reviewed_by = $actor->id;
        $task->reviewed_at = Carbon::now();
        $task->save();

        $latestSubmission = TaskSubmission::where('task_id', $task->id)->orderBy('submission_number', 'desc')->first();
        if ($latestSubmission) {
            $latestSubmission->marks_awarded = $newMarks;
            $latestSubmission->save();
        }

        // Audit Trail
        TaskActivity::log(
            $task->id,
            'marks_updated',
            "Marks updated from {$prevMarks}/{$maxMarks} to {$newMarks}/{$maxMarks} by {$actor->name}. Reason: " . ($request->reason ?: 'Admin score adjustment'),
            $actor,
            null,
            null,
            $latestSubmission?->id,
            ['previous_marks' => $prevMarks, 'new_marks' => $newMarks, 'reason' => $request->reason]
        );

        $assignee = User::find($task->assigned_to);
        if ($assignee) {
            NotificationService::create(
                $task->organization_id,
                $assignee->id,
                'Task Marks Updated',
                "Your marks for task \"{$task->title}\" have been updated to {$newMarks}/{$maxMarks}.",
                'info',
                '/employee/tasks'
            );
        }

        return response()->json([
            'message' => "Marks successfully updated to {$newMarks}/{$maxMarks}!",
            'task' => $task
        ]);
    }

    /**
     * Download attached proof file securely
     */
    public function downloadProofFile(Request $request, $taskId, $fileId)
    {
        $user = $request->user();
        $role = $this->getRoleName($user);

        $task = Task::where('organization_id', $user->organization_id)->where('id', $taskId)->first();
        if (!$task) {
            return response()->json(['message' => 'Task not found'], 404);
        }

        $file = TaskSubmissionFile::where('task_id', $taskId)->where('id', $fileId)->first();
        if (!$file) {
            return response()->json(['message' => 'Proof file not found'], 404);
        }

        // Authorization check
        $canAccess = in_array($role, ['admin', 'hr', 'manager', 'team_leader'])
            || (int)$task->assigned_to === (int)$user->id
            || (int)$task->assigner_id === (int)$user->id;

        if (!$canAccess) {
            return response()->json(['message' => 'Unauthorized file access'], 403);
        }

        if (!Storage::disk('local')->exists($file->file_path)) {
            return response()->json(['message' => 'File not found on storage'], 404);
        }

        return Storage::disk('local')->download($file->file_path, $file->original_name);
    }

    /**
     * Stream attached proof file for inline preview (PDF / Images / Docs)
     */
    public function viewProofFile(Request $request, $taskId, $fileId)
    {
        $user = $request->user();
        $role = $this->getRoleName($user);

        $task = Task::where('organization_id', $user->organization_id)->where('id', $taskId)->first();
        if (!$task) {
            return response()->json(['message' => 'Task not found'], 404);
        }

        $file = TaskSubmissionFile::where('task_id', $taskId)->where('id', $fileId)->first();
        if (!$file) {
            return response()->json(['message' => 'Proof file not found'], 404);
        }

        $canAccess = in_array($role, ['admin', 'hr', 'manager', 'team_leader'])
            || (int)$task->assigned_to === (int)$user->id
            || (int)$task->assigner_id === (int)$user->id;

        if (!$canAccess) {
            return response()->json(['message' => 'Unauthorized file access'], 403);
        }

        if (!Storage::disk('local')->exists($file->file_path)) {
            return response()->json(['message' => 'File not found on storage'], 404);
        }

        $mime = Storage::disk('local')->mimeType($file->file_path) ?: 'application/octet-stream';
        $content = Storage::disk('local')->get($file->file_path);

        return response($content, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => "inline; filename=\"{$file->original_name}\"",
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Get all submission iterations for a task
     */
    public function submissions(Request $request, $id)
    {
        $user = $request->user();
        $submissions = TaskSubmission::where('task_id', $id)
            ->with(['files', 'reviewer:id,name', 'employee:id,name'])
            ->orderBy('submission_number', 'asc')
            ->get();

        return response()->json(['submissions' => $submissions]);
    }

    /**
     * Get full activity log / audit trail for a task
     */
    public function history(Request $request, $id)
    {
        $user = $request->user();
        $activities = TaskActivity::where('task_id', $id)
            ->with(['performer:id,name,role_id', 'performer.role:id,name,display_name'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['activities' => $activities]);
    }

    /**
     * Toggle subtask checklist item
     */
    public function toggleSubtask(Request $request, $id)
    {
        $this->ensureSchemaIntegrity();
        $user = $request->user();
        $role = $this->getRoleName($user);
        $task = Task::where('organization_id', $user->organization_id)
            ->where('id', $id)
            ->first();

        if (!$task) {
            return response()->json(['message' => 'Task not found'], 404);
        }

        $isAdminOrAssigner = in_array($role, ['admin', 'hr', 'manager', 'team_leader']) || (int)$task->assigner_id === (int)$user->id;

        if ((int)$task->assigned_to !== (int)$user->id && !$isAdminOrAssigner) {
            return response()->json(['message' => 'Unauthorized to toggle checklist subtasks.'], 403);
        }

        $request->validate(['subtask_id' => 'required']);

        $subtaskId = $request->subtask_id;
        $subtasks = $task->subtasks ?? [];
        if (!is_array($subtasks)) $subtasks = [];

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
        }
        $task->save();

        return response()->json([
            'message' => 'Subtask updated successfully',
            'task' => $task->fresh([
                'assigner:id,name,email,avatar,role_id',
                'assigner.role:id,name,display_name',
                'assignedTo:id,name,email,avatar,department,designation,role_id',
                'assignedTo.role:id,name,display_name',
            ])
        ]);
    }

    /**
     * Update task status & progress (Management or Assignee)
     */
    public function updateStatus(Request $request, $id)
    {
        $this->ensureSchemaIntegrity();
        $user = $request->user();
        $role = $this->getRoleName($user);
        $task = Task::where('organization_id', $user->organization_id)->where('id', $id)->first();

        if (!$task) {
            return response()->json(['message' => 'Task not found'], 404);
        }

        $isAdminOrAssigner = in_array($role, ['admin', 'hr', 'manager', 'team_leader']) || (int)$task->assigner_id === (int)$user->id;

        if ((int)$task->assigned_to !== (int)$user->id && !$isAdminOrAssigner) {
            return response()->json(['message' => 'Unauthorized to update task status.'], 403);
        }

        $request->validate([
            'status' => 'required|in:todo,assigned,in_progress,submitted_for_review,approved,needs_revision,completed,overdue,cancelled',
            'completion_notes' => 'nullable|string',
            'progress_percentage' => 'nullable|integer|min:0|max:100',
        ]);

        $newStatus = $request->status;
        if ($newStatus === 'assigned') $newStatus = 'todo';
        $prevStatus = $task->status;

        // Employees must not directly approve their own tasks
        if (!$isAdminOrAssigner && in_array($newStatus, ['approved', 'completed'])) {
            return response()->json([
                'message' => 'Verification Required: Employees must use "Submit for Review" with completion proof. Only Admin/Management can approve tasks.'
            ], 422);
        }

        $task->status = $newStatus;
        if ($request->has('progress_percentage')) {
            $task->progress_percentage = $request->progress_percentage;
        }
        if ($newStatus === 'approved' || $newStatus === 'completed') {
            $task->progress_percentage = 100;
            $task->completed_at = Carbon::now();
        }

        $task->save();

        TaskActivity::log(
            $task->id,
            'task_updated',
            "Task status changed from {$prevStatus} to {$newStatus} by {$user->name}.",
            $user,
            $prevStatus,
            $newStatus
        );

        return response()->json([
            'message' => 'Task status updated successfully',
            'task' => $task->fresh([
                'assigner:id,name,email,avatar,role_id',
                'assigner.role:id,name,display_name',
                'assignedTo:id,name,email,avatar,department,designation,role_id',
                'assignedTo.role:id,name,display_name',
            ])
        ]);
    }

    /**
     * Update task details
     */
    public function update(Request $request, $id)
    {
        $this->ensureSchemaIntegrity();
        $user = $request->user();
        $role = $this->getRoleName($user);

        $task = Task::where('id', $id)->first();
        if (!$task) {
            return response()->json(['message' => 'Task not found'], 404);
        }

        if (in_array($role, ['admin', 'hr'])) {
            // Full access
        } elseif ((int)$task->assigner_id !== (int)$user->id && !in_array($role, ['manager', 'team_leader'])) {
            return response()->json(['message' => 'Unauthorized: Only the task creator or management can edit this task.'], 403);
        }

        $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'assigned_to' => 'nullable|exists:users,id',
            'priority' => 'nullable|in:low,medium,high,urgent',
            'category' => 'nullable|string',
            'maximum_marks' => 'nullable|integer|min:1|max:1000',
            'start_date' => 'nullable|date',
            'due_date' => 'nullable|date',
            'subtasks' => 'nullable|array',
            'notes' => 'nullable|string',
        ]);

        $changes = [];
        if ($request->has('title') && $request->title !== $task->title) {
            $changes[] = "Title updated to \"{$request->title}\"";
            $task->title = $request->title;
        }
        if ($request->has('description') && $request->description !== $task->description) {
            $changes[] = "Work description modified";
            $task->description = $request->description;
        }
        if ($request->has('maximum_marks') && (int)$request->maximum_marks !== (int)$task->maximum_marks) {
            $changes[] = "Maximum marks adjusted from {$task->maximum_marks} to {$request->maximum_marks}";
            $task->maximum_marks = (int)$request->maximum_marks;
        }
        if ($request->has('priority') && $request->priority !== $task->priority) {
            $changes[] = "Priority changed to " . ucfirst($request->priority);
            $task->priority = $request->priority;
        }
        if ($request->has('category') && $request->category !== $task->category) {
            $changes[] = "Category changed to " . ucfirst($request->category);
            $task->category = $request->category;
        }
        if ($request->has('due_date')) {
            $task->due_date = $request->due_date;
            $changes[] = "Due date set to {$request->due_date}";
        }
        if ($request->has('subtasks')) {
            $task->subtasks = $request->subtasks;
            $changes[] = "Checklist items updated";
        }
        if ($request->has('notes')) {
            $task->notes = $request->notes;
        }

        if (count($changes) > 0) {
            $summaryText = implode('; ', $changes);
            $history = $task->edit_history ?? [];
            if (!is_array($history)) $history = [];
            array_unshift($history, [
                'id' => (string) str_replace('.', '', uniqid('rev_', true)),
                'editor_id' => $user->id,
                'editor_name' => $user->name,
                'editor_role' => $user->role->display_name ?? ucfirst($role),
                'timestamp' => Carbon::now()->toIso8601String(),
                'summary' => $summaryText,
                'changes' => $changes,
            ]);

            $task->edit_history = array_slice($history, 0, 25);
            $task->last_edited_by = $user->id;
            $task->last_edited_at = Carbon::now();
            $task->last_edit_summary = $summaryText;

            TaskActivity::log(
                $task->id,
                'task_updated',
                "Task updated by {$user->name}: {$summaryText}",
                $user,
                null,
                null,
                null,
                ['changes' => $changes]
            );
        }

        $task->save();

        return response()->json([
            'message' => 'Task updated successfully',
            'task' => $task->fresh([
                'assigner:id,name,email,avatar,role_id',
                'assigner.role:id,name,display_name',
                'assignedTo:id,name,email,avatar,department,designation,role_id',
                'assignedTo.role:id,name,display_name',
                'lastEditor:id,name,email,avatar,role_id',
                'lastEditor.role:id,name,display_name',
            ])
        ]);
    }

    /**
     * Delete task
     */
    public function destroy(Request $request, $id)
    {
        $this->ensureSchemaIntegrity();
        $user = $request->user();
        $role = $this->getRoleName($user);

        $task = Task::where('id', $id)->first();
        if (!$task) {
            return response()->json(['message' => 'Task not found'], 404);
        }

        $isAuthorized = in_array($role, ['admin', 'hr', 'manager', 'team_leader'])
            || (int)$task->assigner_id === (int)$user->id;

        if (!$isAuthorized) {
            return response()->json(['message' => 'Unauthorized to delete this task.'], 403);
        }

        $task->delete();

        return response()->json([
            'message' => 'Task deleted successfully',
            'task_id' => (int)$id,
        ]);
    }

    /**
     * Role-scoped employee performance metrics based on admin-awarded marks
     */
    public function employeePerformance(Request $request)
    {
        $this->ensureSchemaIntegrity();
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
            $query->where('manager_id', $user->id);
        } elseif ($role === 'manager') {
            $tlIds = User::where('organization_id', $user->organization_id)
                ->where('manager_id', $user->id)
                ->pluck('id')
                ->toArray();
            $query->where(function ($q) use ($user, $tlIds) {
                $q->where('manager_id', $user->id)
                  ->orWhereIn('manager_id', $tlIds);
            });
        } elseif ($role === 'hr') {
            $query->where(function ($q) use ($user) {
                $q->where('manager_id', $user->id)
                  ->orWhere('department', $user->department)
                  ->orWhereNull('manager_id');
            });
        }

        $employees = $query->orderBy('name', 'asc')->get();
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');

        $performances = $employees->map(function ($emp) use ($startDate, $endDate) {
            return TaskPerformanceService::calculateEmployeePerformance($emp, $startDate, $endDate);
        })->sortByDesc('performance_percentage')->values();

        $totalOrgTasks = Task::where('organization_id', $user->organization_id)->count();
        $totalApprovedTasks = Task::where('organization_id', $user->organization_id)->whereIn('status', ['approved', 'completed'])->count();
        $totalEarnedAll = (int) Task::where('organization_id', $user->organization_id)->whereIn('status', ['approved', 'completed'])->sum('marks_awarded');
        $totalPossibleAll = (int) Task::where('organization_id', $user->organization_id)->whereIn('status', ['approved', 'completed'])->sum('maximum_marks');

        $overallPerformance = $totalPossibleAll > 0
            ? round(($totalEarnedAll / $totalPossibleAll) * 100, 1)
            : ($totalApprovedTasks > 0 ? 100.0 : 0.0);

        $summary = [
            'overall_completion_rate' => $overallPerformance,
            'overall_performance_rate' => $overallPerformance,
            'total_organization_tasks' => $totalOrgTasks,
            'total_completed_tasks' => $totalApprovedTasks,
            'total_approved_tasks' => $totalApprovedTasks,
            'total_earned_marks' => $totalEarnedAll,
            'total_possible_marks' => $totalPossibleAll,
            'total_employees' => $performances->count(),
        ];

        return response()->json([
            'performances' => $performances,
            'summary' => $summary,
        ]);
    }

    /**
     * Employee Personal Performance API
     */
    public function employeeSelfPerformance(Request $request)
    {
        $this->ensureSchemaIntegrity();
        $user = $request->user();
        $performance = TaskPerformanceService::calculateEmployeePerformance($user);

        $taskDetails = Task::where('assigned_to', $user->id)
            ->where('organization_id', $user->organization_id)
            ->select('id', 'title', 'category', 'priority', 'status', 'maximum_marks', 'marks_awarded', 'due_date', 'reviewed_at', 'admin_feedback')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($t) {
                $percentage = ($t->marks_awarded !== null && $t->maximum_marks > 0)
                    ? round(($t->marks_awarded / $t->maximum_marks) * 100, 1)
                    : null;
                return [
                    'id' => $t->id,
                    'title' => $t->title,
                    'category' => $t->category,
                    'priority' => $t->priority,
                    'status' => $t->status,
                    'maximum_marks' => $t->maximum_marks,
                    'marks_awarded' => $t->marks_awarded,
                    'percentage' => $percentage,
                    'due_date' => $t->due_date,
                    'reviewed_at' => $t->reviewed_at,
                    'admin_feedback' => $t->admin_feedback,
                ];
            });

        return response()->json([
            'summary' => $performance,
            'tasks' => $taskDetails,
        ]);
    }

    /**
     * Dashboard Summary Stats API (Single Source of Truth)
     */
    public function dashboardStats(Request $request)
    {
        $this->ensureSchemaIntegrity();
        $user = $request->user();
        $role = $this->getRoleName($user);

        $indexRes = $this->index($request)->getData(true);
        $taskMetrics = $indexRes['metrics'] ?? [];

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
}
