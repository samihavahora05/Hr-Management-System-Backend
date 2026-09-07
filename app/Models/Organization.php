<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Organization extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'settings',
    ];

    protected $casts = [
        'settings' => 'array',
    ];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    /**
     * Idempotently get or create the default organization structure.
     */
    public static function getDefault(): self
    {
        return self::firstOrCreate(
            ['code' => 'BLUEBOXX'],
            [
                'name' => 'BLUEBOXX HRMS Enterprise Pvt Ltd',
                'settings' => [
                    'timezone' => 'Asia/Kolkata',
                    'currency' => 'INR',
                    'fiscal_year_start' => '04-01',
                    'logo_url' => '/images/logoblue.png',
                    'icon_logo_url' => '/images/Boxxlogo.png',
                    'office_location' => [
                        'enabled' => true,
                        'name' => 'Main Office Headquarters',
                        'latitude' => 22.3039,
                        'longitude' => 73.1783,
                        'radius_meters' => 500,
                        'address' => 'SF 02, INDIA BULLS MEGA MALL, Dinesh Mill Rd, near Swami Vivekananda Railway Over Bridge, Anand Nagar, Akota, Vadodara, Gujarat 390022',
                    ],
                    'holiday_calendar' => [
                        ['date' => '2026-01-26', 'title' => 'Republic Day'],
                        ['date' => '2026-08-15', 'title' => 'Independence Day'],
                        ['date' => '2026-10-02', 'title' => 'Gandhi Jayanti'],
                        ['date' => '2026-10-20', 'title' => 'Diwali'],
                        ['date' => '2026-12-25', 'title' => 'Christmas'],
                    ]
                ]
            ]
        );
    }
}
