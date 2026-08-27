<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PerformanceCycle extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'title',
        'start_date',
        'end_date',
        'status',
    ];

    public function goals()
    {
        return $this->hasMany(Goal::class, 'cycle_id');
    }

    public function reviews()
    {
        return $this->hasMany(PerformanceReview::class, 'cycle_id');
    }
}
