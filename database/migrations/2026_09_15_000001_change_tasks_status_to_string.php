<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE `tasks` MODIFY COLUMN `status` VARCHAR(50) NOT NULL DEFAULT 'todo'");
            DB::statement("ALTER TABLE `tasks` MODIFY COLUMN `priority` VARCHAR(50) NOT NULL DEFAULT 'medium'");
        } else {
            Schema::table('tasks', function (Blueprint $table) {
                $table->string('status', 50)->default('todo')->change();
                $table->string('priority', 50)->default('medium')->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE `tasks` MODIFY COLUMN `status` ENUM('todo', 'in_progress', 'completed', 'overdue', 'cancelled') NOT NULL DEFAULT 'todo'");
            DB::statement("ALTER TABLE `tasks` MODIFY COLUMN `priority` ENUM('low', 'medium', 'high', 'urgent') NOT NULL DEFAULT 'medium'");
        }
    }
};
