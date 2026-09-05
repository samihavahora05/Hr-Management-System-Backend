<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users')) {
            // Update admin@blueboxx.com to secure bcrypt hash of Blueboxx@2026
            DB::table('users')
                ->where('email', 'admin@blueboxx.com')
                ->update([
                    'password' => Hash::make('Blueboxx@2026'),
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // No-op to preserve security
    }
};
