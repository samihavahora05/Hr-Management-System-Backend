<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Job Requisitions
        Schema::create('job_requisitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->foreignId('department_id')->nullable()->constrained('departments')->onDelete('set null');
            $table->string('title');
            $table->integer('headcount')->default(1);
            $table->enum('status', ['draft', 'pending_approval', 'approved', 'rejected', 'closed'])->default('pending_approval');
            $table->foreignId('requested_by')->constrained('users')->onDelete('cascade');
            $table->foreignId('approved_by')->nullable()->constrained('users')->onDelete('set null');
            $table->text('justification')->nullable();
            $table->timestamps();
        });

        // 2. Job Openings
        Schema::create('job_openings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->foreignId('requisition_id')->nullable()->constrained('job_requisitions')->onDelete('set null');
            $table->string('title');
            $table->string('department');
            $table->string('location')->default('Mumbai');
            $table->enum('type', ['full_time', 'part_time', 'contract', 'internship'])->default('full_time');
            $table->string('experience_level')->default('1-3 Years');
            $table->text('description');
            $table->enum('status', ['draft', 'active', 'on_hold', 'closed'])->default('active');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        // 3. Candidates
        Schema::create('candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->foreignId('job_opening_id')->constrained('job_openings')->onDelete('cascade');
            $table->string('name');
            $table->string('email');
            $table->string('phone')->nullable();
            $table->string('resume_url')->nullable();
            $table->enum('stage', ['applied', 'screening', 'interview', 'selected', 'offered', 'joined', 'rejected'])->default('applied');
            $table->integer('rating')->default(0); // 1-5 stars
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // 4. Interviews
        Schema::create('interviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->foreignId('candidate_id')->constrained('candidates')->onDelete('cascade');
            $table->foreignId('interviewer_id')->constrained('users')->onDelete('cascade');
            $table->dateTime('scheduled_at');
            $table->string('location_link')->nullable();
            $table->enum('status', ['scheduled', 'completed', 'cancelled'])->default('scheduled');
            $table->integer('rating')->nullable();
            $table->text('feedback')->nullable();
            $table->timestamps();
        });

        // 5. Job Offers
        Schema::create('job_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->foreignId('candidate_id')->constrained('candidates')->onDelete('cascade');
            $table->decimal('salary_offered', 12, 2);
            $table->date('joining_date');
            $table->enum('status', ['draft', 'sent', 'accepted', 'declined'])->default('draft');
            $table->string('offer_letter_url')->nullable();
            $table->foreignId('converted_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_offers');
        Schema::dropIfExists('interviews');
        Schema::dropIfExists('candidates');
        Schema::dropIfExists('job_openings');
        Schema::dropIfExists('job_requisitions');
    }
};
