<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Candidate extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'job_opening_id',
        'name',
        'email',
        'phone',
        'resume_url',
        'stage',
        'rating',
        'notes',
    ];

    public function jobOpening()
    {
        return $this->belongsTo(JobOpening::class);
    }

    public function interviews()
    {
        return $this->hasMany(Interview::class);
    }

    public function offer()
    {
        return $this->hasOne(JobOffer::class);
    }
}
