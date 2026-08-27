<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExpenseClaim extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'user_id',
        'category',
        'amount',
        'claim_date',
        'description',
        'receipt_url',
        'status',
        'approver_id',
        'rejection_reason',
    ];

    protected $casts = [
        'claim_date' => 'date:Y-m-d',
        'amount' => 'decimal:2',
    ];

    protected function serializeDate(\DateTimeInterface $date): string
    {
        return $date->format('Y-m-d');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approver_id');
    }
}
