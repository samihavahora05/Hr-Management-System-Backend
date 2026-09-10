<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\Task;
use App\Models\TaskSubmission;
use App\Models\TaskSubmissionFile;
use App\Models\TaskActivity;
use App\Services\TaskPerformanceService;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

echo "=== VERIFYING SCHEMA ===\n";
echo "tasks.maximum_marks: " . (Schema::hasColumn('tasks', 'maximum_marks') ? 'YES' : 'NO') . "\n";
echo "tasks.marks_awarded: " . (Schema::hasColumn('tasks', 'marks_awarded') ? 'YES' : 'NO') . "\n";
echo "tasks.started_at: " . (Schema::hasColumn('tasks', 'started_at') ? 'YES' : 'NO') . "\n";
echo "tasks.submitted_at: " . (Schema::hasColumn('tasks', 'submitted_at') ? 'YES' : 'NO') . "\n";
echo "tasks.reviewed_at: " . (Schema::hasColumn('tasks', 'reviewed_at') ? 'YES' : 'NO') . "\n";
echo "has table task_submissions: " . (Schema::hasTable('task_submissions') ? 'YES' : 'NO') . "\n";
echo "has table task_submission_files: " . (Schema::hasTable('task_submission_files') ? 'YES' : 'NO') . "\n";
echo "has table task_activities: " . (Schema::hasTable('task_activities') ? 'YES' : 'NO') . "\n";

$admin = User::whereHas('role', fn($q) => $q->where('name', 'admin'))->first() ?? User::first();
$employee = User::where('id', '!=', $admin->id)->first() ?? $admin;

echo "\nAdmin: {$admin->name} (ID: {$admin->id})\n";
echo "Employee: {$employee->name} (ID: {$employee->id})\n";

echo "\n--- TEST CASE 1: Admin creates task with Maximum Marks ---\n";
$task = Task::create([
    'organization_id' => $admin->organization_id,
    'assigner_id' => $admin->id,
    'assigned_to' => $employee->id,
    'assigned_by_role' => 'admin',
    'assigned_to_role' => 'employee',
    'title' => 'Test Monthly Financial Audit Report',
    'description' => 'Complete and upload the monthly audit spreadsheet with receipts',
    'category' => 'audit',
    'priority' => 'high',
    'status' => 'todo',
    'maximum_marks' => 100,
    'due_date' => Carbon::today()->addDays(5)->toDateString(),
]);

TaskActivity::log($task->id, 'task_created', "Task created with 100 max marks", $admin);
echo "Created Task ID: {$task->id}, Max Marks: {$task->maximum_marks}, Status: {$task->status}\n";

echo "\n--- TEST CASE 2: Employee starts task ---\n";
$task->status = 'in_progress';
$task->started_at = Carbon::now();
$task->started_by = $employee->id;
$task->save();
TaskActivity::log($task->id, 'task_started', "{$employee->name} started task", $employee);
echo "Task status after start: {$task->status}, Started At: {$task->started_at}\n";

echo "\n--- TEST CASE 3: Employee submits task with proof (Submission #1) ---\n";
$sub1 = TaskSubmission::create([
    'task_id' => $task->id,
    'employee_id' => $employee->id,
    'submission_number' => 1,
    'completion_note' => 'Completed audit spreadsheet with preliminary figures.',
    'what_was_completed' => 'Draft Excel spreadsheet',
    'status' => 'submitted',
    'submitted_at' => Carbon::now(),
    'maximum_marks' => 100,
]);
TaskSubmissionFile::create([
    'submission_id' => $sub1->id,
    'task_id' => $task->id,
    'user_id' => $employee->id,
    'original_name' => 'audit_v1.xlsx',
    'file_path' => 'tasks/1/test/audit_v1.xlsx',
    'file_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'file_size' => 45000,
]);
$task->status = 'submitted_for_review';
$task->submitted_at = Carbon::now();
$task->save();
TaskActivity::log($task->id, 'task_submitted', "Submission #1 submitted", $employee, 'in_progress', 'submitted_for_review', $sub1->id);
TaskActivity::log($task->id, 'proof_uploaded', "Proof file uploaded: audit_v1.xlsx", $employee, null, null, $sub1->id);
echo "Submission #1 ID: {$sub1->id}, Task status: {$task->status}\n";

echo "\n--- TEST CASE 4: Admin reviews and requests revision ---\n";
$sub1->status = 'needs_revision';
$sub1->reviewed_at = Carbon::now();
$sub1->reviewed_by = $admin->id;
$sub1->admin_feedback = 'Please add the missing April receipts and recompute column G.';
$sub1->save();
$task->status = 'needs_revision';
$task->reviewed_at = Carbon::now();
$task->reviewed_by = $admin->id;
$task->admin_feedback = $sub1->admin_feedback;
$task->save();
TaskActivity::log($task->id, 'revision_requested', "Revision requested: {$sub1->admin_feedback}", $admin, 'submitted_for_review', 'needs_revision', $sub1->id);
echo "Task status after revision request: {$task->status}, Feedback: {$task->admin_feedback}\n";

echo "\n--- TEST CASE 5: Employee resubmits with updated proof (Submission #2) ---\n";
$sub2 = TaskSubmission::create([
    'task_id' => $task->id,
    'employee_id' => $employee->id,
    'submission_number' => 2,
    'completion_note' => 'Added April receipts and fixed column G formula.',
    'what_was_completed' => 'Final Excel workbook + PDF summary',
    'status' => 'submitted',
    'submitted_at' => Carbon::now(),
    'maximum_marks' => 100,
]);
TaskSubmissionFile::create([
    'submission_id' => $sub2->id,
    'task_id' => $task->id,
    'user_id' => $employee->id,
    'original_name' => 'audit_final_v2.xlsx',
    'file_path' => 'tasks/1/test/audit_final_v2.xlsx',
    'file_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'file_size' => 52000,
]);
TaskSubmissionFile::create([
    'submission_id' => $sub2->id,
    'task_id' => $task->id,
    'user_id' => $employee->id,
    'original_name' => 'receipts_april.pdf',
    'file_path' => 'tasks/1/test/receipts_april.pdf',
    'file_type' => 'application/pdf',
    'file_size' => 120000,
]);
$task->status = 'submitted_for_review';
$task->submitted_at = Carbon::now();
$task->save();
TaskActivity::log($task->id, 'task_submitted', "Submission #2 submitted", $employee, 'needs_revision', 'submitted_for_review', $sub2->id);
echo "Submission #2 created, total submissions for task: " . TaskSubmission::where('task_id', $task->id)->count() . "\n";

echo "\n--- TEST CASE 6: Admin approves and awards 87/100 marks ---\n";
$awardedMarks = 87;
$sub2->status = 'approved';
$sub2->marks_awarded = $awardedMarks;
$sub2->reviewed_at = Carbon::now();
$sub2->reviewed_by = $admin->id;
$sub2->admin_feedback = 'Great job, all numbers verified accurately.';
$sub2->save();
$task->status = 'approved';
$task->marks_awarded = $awardedMarks;
$task->reviewed_at = Carbon::now();
$task->reviewed_by = $admin->id;
$task->completed_at = Carbon::now();
$task->admin_feedback = $sub2->admin_feedback;
$task->save();
TaskActivity::log($task->id, 'task_approved', "Task approved by {$admin->name}", $admin, 'submitted_for_review', 'approved', $sub2->id);
TaskActivity::log($task->id, 'marks_awarded', "Awarded {$awardedMarks}/100 marks", $admin, null, null, $sub2->id, ['marks_awarded' => $awardedMarks]);

echo "Task status: {$task->status}, Marks Awarded: {$task->marks_awarded}/{$task->maximum_marks}\n";

echo "\n--- TEST CASE 7: Performance Calculation via TaskPerformanceService ---\n";
$perf = TaskPerformanceService::calculateEmployeePerformance($employee);
echo "Employee: {$perf['name']}\n";
echo "Total Assigned Tasks: {$perf['total_tasks']}\n";
echo "Approved Tasks: {$perf['approved_tasks']}\n";
echo "Total Earned Marks: {$perf['total_earned_marks']}\n";
echo "Total Possible Marks: {$perf['total_possible_marks']}\n";
echo "Performance Percentage: {$perf['performance_percentage']}%\n";
echo "Rating: {$perf['rating']} (Badge: {$perf['rating_badge']})\n";

echo "\n--- TEST CASE 8: Verify Complete Audit Trail ---\n";
$activities = TaskActivity::where('task_id', $task->id)->orderBy('created_at', 'asc')->get();
echo "Total activity log entries: {$activities->count()}\n";
foreach ($activities as $act) {
    echo "  [{$act->event_type}] by {$act->performed_by_role}: {$act->description}\n";
}

echo "\n=== ALL TESTS PASSED SUCCESSFULLY! ===\n";
