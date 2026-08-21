<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Location extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'name',
        'city',
        'state',
        'country',
        'postal_code',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }
}
