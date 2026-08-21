<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LeaveType extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'name',
        'annual_quota',
        'is_paid',
    ];

    protected $casts = [
        'is_paid' => 'boolean',
    ];
}
