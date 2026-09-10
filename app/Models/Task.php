<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'assigner_id',
        'assigned_to',
        'assigned_by_role',
        'assigned_to_role',
        'title',
        'description',
        'category',
        'priority',
        'status',
        'progress_percentage',
        'start_date',
        'due_date',
        'subtasks',
        'notes',
        'completion_notes',
        'completed_at',
        'maximum_marks',
        'marks_awarded',
        'started_at',
        'started_by',
        'submitted_at',
        'reviewed_at',
        'reviewed_by',
        'admin_feedback',
        'last_edited_by',
        'last_edited_at',
        'last_edit_summary',
        'edit_history',
    ];

    protected $casts = [
        'start_date' => 'date:Y-m-d',
        'due_date' => 'date:Y-m-d',
        'subtasks' => 'array',
        'edit_history' => 'array',
        'progress_percentage' => 'integer',
        'maximum_marks' => 'integer',
        'marks_awarded' => 'integer',
        'completed_at' => 'datetime:Y-m-d H:i:s',
        'started_at' => 'datetime:Y-m-d H:i:s',
        'submitted_at' => 'datetime:Y-m-d H:i:s',
        'reviewed_at' => 'datetime:Y-m-d H:i:s',
        'last_edited_at' => 'datetime:Y-m-d H:i:s',
    ];

    protected function serializeDate(\DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function assigner()
    {
        return $this->belongsTo(User::class, 'assigner_id');
    }

    public function assignedTo()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function assignedUser()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function getAssignedUserAttribute()
    {
        return $this->relationLoaded('assignedTo') ? $this->assignedTo : null;
    }

    public function starter()
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function lastEditor()
    {
        return $this->belongsTo(User::class, 'last_edited_by');
    }

    public function submissions()
    {
        return $this->hasMany(TaskSubmission::class)->orderBy('submission_number', 'asc');
    }

    public function latestSubmission()
    {
        return $this->hasOne(TaskSubmission::class)->latestOfMany();
    }

    public function files()
    {
        return $this->hasMany(TaskSubmissionFile::class);
    }

    public function activities()
    {
        return $this->hasMany(TaskActivity::class)->orderBy('created_at', 'desc');
    }

    /**
     * Mutator to guarantee status always complies with database check constraints and lifecycle standards.
     */
    public function setStatusAttribute($value)
    {
        $allowed = ['todo', 'assigned', 'in_progress', 'submitted_for_review', 'approved', 'needs_revision', 'completed', 'overdue', 'cancelled'];
        if ($value === 'pending') {
            $this->attributes['status'] = 'todo';
        } elseif ($value === 'under_review') {
            $this->attributes['status'] = 'submitted_for_review';
        } elseif (in_array($value, $allowed, true)) {
            $this->attributes['status'] = $value;
        } else {
            $this->attributes['status'] = 'todo';
        }
    }
}
