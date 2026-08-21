<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class JobOpening extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'requisition_id',
        'title',
        'department',
        'location',
        'type',
        'experience_level',
        'vacancies',
        'description',
        'status',
        'published_at',
    ];

    public function candidates()
    {
        return $this->hasMany(Candidate::class);
    }
}
