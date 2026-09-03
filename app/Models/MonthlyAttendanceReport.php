<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MonthlyAttendanceReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'created_by',
        'title',
        'month',
        'year',
        'month_name',
        'department',
        'total_employees',
        'total_working_days',
        'avg_attendance_percentage',
        'avg_performance_rate',
        'summary',
        'records',
        'status',
        'notes',
    ];

    protected $casts = [
        'summary' => 'array',
        'records' => 'array',
        'avg_attendance_percentage' => 'float',
        'avg_performance_rate' => 'float',
        'total_employees' => 'integer',
        'total_working_days' => 'integer',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }
}
