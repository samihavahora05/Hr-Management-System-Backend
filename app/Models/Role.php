<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'display_name',
        'description',
    ];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    /**
     * Idempotently ensure the 5 canonical HRMS roles exist in the database.
     * This avoids any mandatory dependency on database seeders.
     */
    public static function ensureStandardRoles(): void
    {
        $standardRoles = [
            [
                'name' => 'admin',
                'display_name' => 'Admin',
                'description' => 'Full administrative control and executive management',
            ],
            [
                'name' => 'hr',
                'display_name' => 'HR Manager',
                'description' => 'Manages HR operations, employees, and statutory payroll',
            ],
            [
                'name' => 'manager',
                'display_name' => 'Company Manager',
                'description' => 'Manages department heads, team leaders, and projects',
            ],
            [
                'name' => 'team_leader',
                'display_name' => 'Team Leader',
                'description' => 'Manages direct team members and day-to-day task execution',
            ],
            [
                'name' => 'employee',
                'display_name' => 'Employee',
                'description' => 'Self-service portal, tasks, attendance, and leave requests',
            ],
        ];

        foreach ($standardRoles as $roleData) {
            self::firstOrCreate(
                ['name' => $roleData['name']],
                [
                    'display_name' => $roleData['display_name'],
                    'description' => $roleData['description'],
                ]
            );
        }
    }

    /**
     * Safely lookup a role by name or alias, ensuring standard roles are present if not found.
     */
    public static function getByName(?string $name): self
    {
        $normalized = strtolower(trim((string) $name));
        if (in_array($normalized, ['company_manager', 'manager'])) {
            $normalized = 'manager';
        } elseif (in_array($normalized, ['tl', 'team_lead', 'team_leader'])) {
            $normalized = 'team_leader';
        } elseif (empty($normalized)) {
            $normalized = 'employee';
        }

        $role = self::where('name', $normalized)->first();
        if (!$role) {
            self::ensureStandardRoles();
            $role = self::where('name', $normalized)->first() 
                ?? self::where('name', 'employee')->first()
                ?? self::firstOrCreate(['name' => 'employee'], ['display_name' => 'Employee', 'description' => 'Employee self-service']);
        }

        return $role;
    }
}
