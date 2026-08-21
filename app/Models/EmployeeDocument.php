<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployeeDocument extends Model
{
    use HasFactory;

    protected $table = 'documents';

    protected $fillable = [
        'organization_id',
        'user_id',
        'title',
        'type',
        'file_url',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
