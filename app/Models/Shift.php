<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Shift extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'name',
        'start_time',
        'end_time',
        'grace_period_minutes',
        'work_days',
    ];

    protected $casts = [
        'work_days' => 'array',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function employees()
    {
        return $this->hasMany(User::class);
    }
}
