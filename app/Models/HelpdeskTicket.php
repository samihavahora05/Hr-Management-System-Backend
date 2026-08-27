<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HelpdeskTicket extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'ticket_number',
        'requester_id',
        'assigned_to',
        'category',
        'priority',
        'subject',
        'description',
        'status',
        'resolution_notes',
    ];

    public function requester()
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
