<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Http\Controllers\AttendanceController;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('attendance:auto-checkout', function () {
    $count = (new AttendanceController)->processAutoCheckouts();
    $this->info("Auto checked out {$count} pending attendance record(s) (6:00 PM Mon-Fri, 2:00 PM Saturdays).");
})->purpose('Automatically check out employees (6:00 PM weekdays, 2:00 PM Saturdays)');

Schedule::call(function () {
    (new AttendanceController)->processAutoCheckouts();
})->everyMinute()->name('attendance-auto-checkout');
