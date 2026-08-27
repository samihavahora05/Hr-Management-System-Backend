<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'organization_id',
        'role_id',
        'branch_id',
        'location_id',
        'shift_id',
        'team_id',
        'job_role_id',
        'name',
        'email',
        'password',
        'employee_code',
        'department',
        'designation',
        'joining_date',
        'status',
        'phone',
        'avatar',
        'base_salary',
        'manager_id',
        'gender',
        'dob',
        'work_mode',
        'probation_status',
        'confirmation_date',
        'emergency_contact',
        'pan',
        'bank_details',
        'pf_number',
        'esi_number',
        'tax_regime',
        'remember_token',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime:Y-m-d',
            'password' => 'hashed',
            'joining_date' => 'date:Y-m-d',
            'dob' => 'date:Y-m-d',
            'confirmation_date' => 'date:Y-m-d',
            'base_salary' => 'decimal:2',
            'emergency_contact' => 'array',
            'bank_details' => 'array',
        ];
    }

    protected function serializeDate(\DateTimeInterface $date): string
    {
        return $date->format('Y-m-d');
    }

    public function createToken($name)
    {
        $token = Str::random(80);
        $this->remember_token = $token;
        $this->save();

        return new class($token) {
            public $plainTextToken;
            public function __construct($t) {
                $this->plainTextToken = $t;
            }
        };
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function jobRole()
    {
        return $this->belongsTo(JobRole::class);
    }

    public function hasRole($roles): bool
    {
        $roleName = strtolower($this->role->name ?? 'employee');
        if ($roleName === 'admin') {
            return true;
        }
        if (is_array($roles)) {
            return in_array($roleName, array_map('strtolower', $roles));
        }
        return $roleName === strtolower($roles);
    }

    public function getCanonicalRole(): string
    {
        if (!$this->relationLoaded('role') && $this->role_id) {
            $this->load('role');
        }
        $name = strtolower($this->role->name ?? 'employee');
        if ($name === 'admin') return 'admin';
        if ($name === 'hr') return 'hr';
        if (in_array($name, ['manager', 'company_manager'])) return 'manager';
        if (in_array($name, ['team_leader', 'tl', 'team_lead'])) return 'team_leader';
        return 'employee';
    }

    public function isAdmin(): bool
    {
        return $this->getCanonicalRole() === 'admin';
    }

    public function isHR(): bool
    {
        return $this->getCanonicalRole() === 'hr';
    }

    public function isCompanyManager(): bool
    {
        return $this->getCanonicalRole() === 'manager';
    }

    public function isTeamLeader(): bool
    {
        return $this->getCanonicalRole() === 'team_leader';
    }

    public function isEmployee(): bool
    {
        return $this->getCanonicalRole() === 'employee';
    }

    public function manager()
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function directReports()
    {
        return $this->hasMany(User::class, 'manager_id');
    }

    public function attendances()
    {
        return $this->hasMany(Attendance::class);
    }

    public function leaveRequests()
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function leaveBalances()
    {
        return $this->hasMany(LeaveBalance::class);
    }

    public function documents()
    {
        return $this->hasMany(EmployeeDocument::class);
    }

    public function riskScores()
    {
        return $this->hasMany(EmployeeRiskScore::class);
    }

    public function latestRiskScore()
    {
        return $this->hasOne(EmployeeRiskScore::class)->latestOfMany();
    }

    public function assignedTasks()
    {
        return $this->hasMany(Task::class, 'assigned_to');
    }

    public function createdTasks()
    {
        return $this->hasMany(Task::class, 'assigner_id');
    }

    public function expenseClaims()
    {
        return $this->hasMany(ExpenseClaim::class);
    }

    public function loanRequests()
    {
        return $this->hasMany(LoanRequest::class);
    }

    public function timesheets()
    {
        return $this->hasMany(Timesheet::class);
    }

    public function assets()
    {
        return $this->hasMany(Asset::class, 'assigned_to');
    }

    public function helpdeskTickets()
    {
        return $this->hasMany(HelpdeskTicket::class, 'requester_id');
    }

    public function notifications()
    {
        return $this->hasMany(Notification::class);
    }
}
