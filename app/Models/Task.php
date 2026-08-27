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
    ];

    protected $casts = [
        'start_date' => 'date:Y-m-d',
        'due_date' => 'date:Y-m-d',
        'subtasks' => 'array',
        'progress_percentage' => 'integer',
        'completed_at' => 'datetime:Y-m-d H:i:s',
    ];

    protected function serializeDate(\DateTimeInterface $date): string
    {
        return $date->format('Y-m-d');
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

    /**
     * Mutator to guarantee status always complies with SQLite database check constraints.
     */
    public function setStatusAttribute($value)
    {
        $allowed = ['todo', 'in_progress', 'completed', 'overdue', 'cancelled'];
        if ($value === 'pending') {
            $this->attributes['status'] = 'todo';
        } elseif ($value === 'under_review') {
            $this->attributes['status'] = 'in_progress';
        } elseif (in_array($value, $allowed, true)) {
            $this->attributes['status'] = $value;
        } else {
            $this->attributes['status'] = 'todo';
        }
    }
}
