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
    $this->info("Auto checked out {$count} pending attendance record(s) based on scheduled shift timings.");
})->purpose('Automatically check out employees past their assigned shift end time');

Schedule::call(function () {
    (new AttendanceController)->processAutoCheckouts();
})->everyMinute()->name('attendance-auto-checkout');
