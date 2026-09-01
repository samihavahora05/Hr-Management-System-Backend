<?php

namespace App\Http\Controllers;

use App\Models\JobOpening;
use App\Models\Candidate;
use App\Models\Interview;
use App\Models\JobOffer;
use App\Models\User;
use App\Models\Role;
use App\Models\LeaveType;
use App\Models\LeaveBalance;
use App\Models\AuditLog;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class RecruitmentController extends Controller
{
    // Job Openings List
    public function getOpenings(Request $request)
    {
        $user = $request->user();

        $openings = JobOpening::where('organization_id', $user->organization_id)
            ->withCount('candidates')
            ->withCount(['candidates as joined_count' => function ($q) {
                $q->where('stage', 'joined');
            }])
            ->orderBy('created_at', 'desc')
            ->get();

        // Auto-close openings where onboarded candidates (joined_count) >= vacancies
        foreach ($openings as $opening) {
            $targetVacancies = max(1, (int) ($opening->vacancies ?? 1));
            if ($opening->joined_count >= $targetVacancies && $opening->status === 'active') {
                $opening->status = 'closed';
                $opening->save();
            }
        }

        return response()->json(['openings' => $openings]);
    }

    // Create Job Opening
    public function storeOpening(Request $request)
    {
        $actor = $request->user();
        if (!in_array($actor->getCanonicalRole(), ['admin', 'hr'])) {
            return response()->json(['message' => 'Unauthorized: Only HR or Admin can create job openings'], 403);
        }

        $request->validate([
            'title' => 'required|string',
            'department' => 'required|string',
            'location' => 'nullable|string',
            'type' => 'nullable|string',
            'experience_level' => 'nullable|string',
            'vacancies' => 'nullable|integer|min:1',
            'description' => 'required|string',
        ]);

        $opening = JobOpening::create([
            'organization_id' => $actor->organization_id,
            'title' => $request->title,
            'department' => $request->department,
            'location' => $request->location ?? 'Mumbai',
            'type' => $request->type ?? 'full_time',
            'experience_level' => $request->experience_level ?? '1-3 Years',
            'vacancies' => $request->vacancies ? (int) $request->vacancies : 1,
            'description' => $request->description,
            'status' => 'active',
            'published_at' => now(),
        ]);

        return response()->json(['message' => 'Job opening created successfully', 'opening' => $opening], 201);
    }

    // Candidates List
    public function getCandidates(Request $request)
    {
        $user = $request->user();
        $query = Candidate::where('organization_id', $user->organization_id)
            ->with(['jobOpening', 'interviews.interviewer', 'offer']);

        if ($request->has('job_opening_id')) {
            $query->where('job_opening_id', $request->job_opening_id);
        }
        if ($request->has('stage')) {
            $query->where('stage', $request->stage);
        }

        $candidates = $query->orderBy('created_at', 'desc')->get();
        return response()->json(['candidates' => $candidates]);
    }

    // Create Candidate
    public function storeCandidate(Request $request)
    {
        $actor = $request->user();
        if (!in_array($actor->getCanonicalRole(), ['admin', 'hr'])) {
            return response()->json(['message' => 'Unauthorized to add candidates'], 403);
        }

        $request->validate([
            'job_opening_id' => 'required|exists:job_openings,id',
            'name' => 'required|string',
            'email' => 'required|email',
            'phone' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $candidate = Candidate::create([
            'organization_id' => $actor->organization_id,
            'job_opening_id' => $request->job_opening_id,
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'resume_url' => $request->resume_url ?? '/resumes/sample_resume.pdf',
            'stage' => 'applied',
            'rating' => 0,
            'notes' => $request->notes,
        ]);

        return response()->json(['message' => 'Candidate added successfully', 'candidate' => $candidate], 201);
    }

    // Update Candidate Stage
    public function updateCandidateStage(Request $request, $id)
    {
        $actor = $request->user();
        if (!in_array($actor->getCanonicalRole(), ['admin', 'hr'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'stage' => 'required|in:applied,screening,interview,selected,offered,joined,rejected',
            'rating' => 'nullable|integer|min:1|max:5',
        ]);

        $candidate = Candidate::where('organization_id', $actor->organization_id)
            ->where('id', $id)
            ->first();

        if (!$candidate) {
            return response()->json(['message' => 'Candidate not found'], 404);
        }

        $candidate->stage = $request->stage;
        if ($request->has('rating')) {
            $candidate->rating = $request->rating;
        }
        $candidate->save();

        return response()->json(['message' => 'Candidate stage updated successfully', 'candidate' => $candidate]);
    }

    // Schedule Interview
    public function scheduleInterview(Request $request)
    {
        $actor = $request->user();
        if (!in_array($actor->getCanonicalRole(), ['admin', 'hr', 'manager'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'candidate_id' => 'required|exists:candidates,id',
            'interviewer_id' => 'required|exists:users,id',
            'scheduled_at' => 'required|date',
            'location_link' => 'nullable|string',
        ]);

        $interview = Interview::create([
            'organization_id' => $actor->organization_id,
            'candidate_id' => $request->candidate_id,
            'interviewer_id' => $request->interviewer_id,
            'scheduled_at' => $request->scheduled_at,
            'location_link' => $request->location_link ?? 'https://meet.google.com/hrms-interview',
            'status' => 'scheduled',
        ]);

        // Update candidate stage to interview
        Candidate::where('id', $request->candidate_id)->update(['stage' => 'interview']);

        // Notify interviewer
        NotificationService::create(
            $actor->organization_id,
            $request->interviewer_id,
            'New Candidate Interview Scheduled',
            "Interview scheduled on {$request->scheduled_at}",
            'info'
        );

        return response()->json(['message' => 'Interview scheduled successfully', 'interview' => $interview], 201);
    }

    // Issue Job Offer & Convert Candidate to Employee Onboarding
    public function issueOfferAndConvert(Request $request, $candidateId)
    {
        $actor = $request->user();
        if (!in_array($actor->getCanonicalRole(), ['admin', 'hr'])) {
            return response()->json(['message' => 'Unauthorized: Only HR or Admin can onboard candidates'], 403);
        }

        $candidate = Candidate::where('organization_id', $actor->organization_id)
            ->where('id', $candidateId)
            ->with('jobOpening')
            ->first();

        if (!$candidate) {
            return response()->json(['message' => 'Candidate not found'], 404);
        }

        $request->validate([
            'salary_offered' => 'required|numeric|min:0',
            'joining_date' => 'required|date',
            'department' => 'nullable|string',
            'designation' => 'nullable|string',
            'manager_id' => 'nullable|exists:users,id',
        ]);

        // Check if user already converted
        $existingUser = User::where('email', $candidate->email)->first();
        if ($existingUser) {
            return response()->json(['message' => 'An employee account already exists for this email.'], 400);
        }

        // Generate Dynamic Sequential Employee Code
        $employeeCode = User::generateNextEmployeeCode($actor->organization_id);
        $role = Role::where('name', 'employee')->first();

        // Create Master Employee User Record
        $user = User::create([
            'organization_id' => $actor->organization_id,
            'role_id' => $role ? $role->id : null,
            'name' => $candidate->name,
            'email' => $candidate->email,
            'password' => Hash::make('welcome123'),
            'employee_code' => $employeeCode,
            'department' => $request->department ?? $candidate->jobOpening->department ?? 'Engineering',
            'designation' => $request->designation ?? $candidate->jobOpening->title ?? 'Staff Member',
            'joining_date' => $request->joining_date,
            'status' => 'active',
            'phone' => $candidate->phone,
            'base_salary' => $request->salary_offered,
            'manager_id' => $request->manager_id,
            'probation_status' => 'probation',
        ]);

        // Auto Allocate Leave Balances
        $leaveTypes = LeaveType::where('organization_id', $actor->organization_id)->get();
        foreach ($leaveTypes as $lt) {
            LeaveBalance::create([
                'organization_id' => $actor->organization_id,
                'user_id' => $user->id,
                'leave_type_id' => $lt->id,
                'allocated' => $lt->annual_quota,
                'used' => 0,
                'remaining' => $lt->annual_quota,
            ]);
        }

        // Create Offer Record
        $offer = JobOffer::create([
            'organization_id' => $actor->organization_id,
            'candidate_id' => $candidate->id,
            'salary_offered' => $request->salary_offered,
            'joining_date' => $request->joining_date,
            'status' => 'accepted',
            'offer_letter_url' => '/offers/Offer_' . $employeeCode . '.pdf',
            'converted_user_id' => $user->id,
        ]);

        // Update Candidate Stage
        $candidate->stage = 'joined';
        $candidate->save();

        if ($candidate->job_opening_id) {
            JobOpening::where('id', $candidate->job_opening_id)
                ->where('organization_id', $actor->organization_id)
                ->update(['status' => 'closed']);
        }

        // Audit Log
        AuditLog::create([
            'organization_id' => $actor->organization_id,
            'actor_id' => $actor->id,
            'action' => 'convert_candidate_to_employee',
            'target_type' => User::class,
            'target_id' => $user->id,
            'payload' => ['candidate_id' => $candidate->id, 'employee_code' => $employeeCode],
        ]);

        NotificationService::create(
            $actor->organization_id,
            $user->id,
            'Welcome to Organization!',
            "Your employee account {$employeeCode} has been created successfully.",
            'success'
        );

        return response()->json([
            'message' => 'Candidate successfully converted into active employee!',
            'employee' => $user->load(['role', 'manager']),
            'offer' => $offer
        ], 201);
    }
}
