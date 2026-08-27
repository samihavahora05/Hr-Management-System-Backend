<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Asset extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'asset_code',
        'name',
        'category',
        'serial_number',
        'assigned_to',
        'assigned_at',
        'returned_at',
        'condition',
        'status',
    ];

    public function assignedEmployee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
