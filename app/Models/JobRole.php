<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class JobRole extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'title',
        'code',
        'description',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }
}
