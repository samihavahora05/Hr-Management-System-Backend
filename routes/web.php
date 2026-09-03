<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Artisan;

Route::get('/', function () {
    return response()->json([
        'status' => 'active',
        'service' => 'Blueboxx HRMS Enterprise API',
        'version' => 'v4.2',
    ]);
});

Route::get('/setup-db', function () {
    try {
        Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
        return response()->json([
            'status' => 'success',
            'message' => 'Database migrated and seeded successfully!',
            'output' => Artisan::output(),
        ]);
    } catch (\Throwable $e) {
        return response()->json([
            'status' => 'error',
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ], 500);
    }
});

// Support API endpoints directly on root (e.g. /auth/login as well as /api/auth/login)
require __DIR__.'/api.php';

// Catch-all preflight OPTIONS
Route::options('{any}', function () {
    return response('', 200);
})->where('any', '.*');
