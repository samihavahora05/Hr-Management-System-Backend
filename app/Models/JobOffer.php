<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class JobOffer extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'candidate_id',
        'salary_offered',
        'joining_date',
        'status',
        'offer_letter_url',
        'converted_user_id',
    ];

    public function candidate()
    {
        return $this->belongsTo(Candidate::class);
    }

    public function convertedUser()
    {
        return $this->belongsTo(User::class, 'converted_user_id');
    }
}
