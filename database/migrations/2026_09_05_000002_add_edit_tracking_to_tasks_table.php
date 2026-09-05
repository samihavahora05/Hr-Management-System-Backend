<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('tasks', 'last_edited_by')) {
            Schema::table('tasks', function (Blueprint $table) {
                $table->foreignId('last_edited_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('last_edited_at')->nullable();
                $table->text('last_edit_summary')->nullable();
                $table->json('edit_history')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            if (Schema::hasColumn('tasks', 'last_edited_by')) {
                $table->dropConstrainedForeignId('last_edited_by');
            }
            if (Schema::hasColumn('tasks', 'last_edited_at')) {
                $table->dropColumn('last_edited_at');
            }
            if (Schema::hasColumn('tasks', 'last_edit_summary')) {
                $table->dropColumn('last_edit_summary');
            }
            if (Schema::hasColumn('tasks', 'edit_history')) {
                $table->dropColumn('edit_history');
            }
        });
    }
};
