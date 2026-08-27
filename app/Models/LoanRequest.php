<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LoanRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'user_id',
        'amount',
        'monthly_installment',
        'tenure_months',
        'reason',
        'status',
        'approver_id',
        'repayment_started_at',
        'outstanding_balance',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approver_id');
    }
}
