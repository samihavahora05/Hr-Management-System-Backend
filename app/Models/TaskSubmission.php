<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TaskSubmission extends Model
{
    use HasFactory;

    protected $fillable = [
        'task_id',
        'employee_id',
        'submission_number',
        'completion_note',
        'what_was_completed',
        'employee_comment',
        'status',
        'submitted_at',
        'reviewed_at',
        'reviewed_by',
        'admin_feedback',
        'marks_awarded',
        'maximum_marks',
    ];

    protected $casts = [
        'submission_number' => 'integer',
        'marks_awarded' => 'integer',
        'maximum_marks' => 'integer',
        'submitted_at' => 'datetime:Y-m-d H:i:s',
        'reviewed_at' => 'datetime:Y-m-d H:i:s',
    ];

    public function task()
    {
        return $this->belongsTo(Task::class);
    }

    public function employee()
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function files()
    {
        return $this->hasMany(TaskSubmissionFile::class, 'submission_id');
    }
}
