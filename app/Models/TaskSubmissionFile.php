<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TaskSubmissionFile extends Model
{
    use HasFactory;

    protected $fillable = [
        'submission_id',
        'task_id',
        'user_id',
        'original_name',
        'file_path',
        'file_type',
        'file_size',
    ];

    protected $casts = [
        'file_size' => 'integer',
    ];

    public function submission()
    {
        return $this->belongsTo(TaskSubmission::class, 'submission_id');
    }

    public function task()
    {
        return $this->belongsTo(Task::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
