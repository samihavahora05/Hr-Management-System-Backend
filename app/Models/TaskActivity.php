<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TaskActivity extends Model
{
    use HasFactory;

    protected $fillable = [
        'task_id',
        'submission_id',
        'event_type',
        'performed_by',
        'performed_by_role',
        'previous_status',
        'new_status',
        'description',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime:Y-m-d H:i:s',
    ];

    public function task()
    {
        return $this->belongsTo(Task::class);
    }

    public function submission()
    {
        return $this->belongsTo(TaskSubmission::class);
    }

    public function performer()
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    /**
     * Helper to easily record task activity event
     */
    public static function log(
        int $taskId,
        string $eventType,
        string $description,
        ?User $user = null,
        ?string $previousStatus = null,
        ?string $newStatus = null,
        ?int $submissionId = null,
        ?array $metadata = null
    ): self {
        return self::create([
            'task_id' => $taskId,
            'submission_id' => $submissionId,
            'event_type' => $eventType,
            'performed_by' => $user?->id,
            'performed_by_role' => $user ? $user->getCanonicalRole() : 'system',
            'previous_status' => $previousStatus,
            'new_status' => $newStatus,
            'description' => $description,
            'metadata' => $metadata,
        ]);
    }
}
