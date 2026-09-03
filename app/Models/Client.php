<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'name',
        'company_name',
        'email',
        'phone',
        'tax_number',
        'address',
        'city',
        'state',
        'postal_code',
        'country',
        'currency',
        'notes',
        'status',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }
}
