<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payroll extends Model
{
    use HasFactory;

    protected $table = 'payrolls';

    protected $fillable = [
        'organization_id',
        'employee_id',
        'pay_period_month',
        'pay_period_year',
        'pay_date',
        'payment_mode',
        'status',
        'earnings',
        'deductions',
        'total_earnings',
        'total_deductions',
        'net_salary',
        'net_salary_words',
        'created_by',
        'paid_at',
        'notes',
    ];

    protected $casts = [
        'earnings' => 'array',
        'deductions' => 'array',
        'total_earnings' => 'decimal:2',
        'total_deductions' => 'decimal:2',
        'net_salary' => 'decimal:2',
        'pay_date' => 'date:Y-m-d',
        'paid_at' => 'datetime',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function employee()
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
