<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'user_id',
        'title',
        'message',
        'type',
        'is_read',
        'link',
    ];

    protected $casts = [
        'is_read' => 'boolean',
    ];

    protected $appends = [
        'action_url',
    ];

    public function getActionUrlAttribute()
    {
        return $this->link;
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
