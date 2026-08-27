<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployeeRiskScore extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'user_id',
        'risk_score',
        'risk_level',
        'contributing_factors',
        'calculated_at',
    ];

    protected $casts = [
        'contributing_factors' => 'array',
        'calculated_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
