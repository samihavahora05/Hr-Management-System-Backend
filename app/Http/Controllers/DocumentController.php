<?php

namespace App\Http\Controllers;

use App\Models\EmployeeDocument;
use App\Models\User;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DocumentController extends Controller
{
    /**
     * Get subordinate employee IDs for hierarchy checks.
     */
    private function getSubordinateUserIds(User $user): array
    {
        $role = $user->getCanonicalRole();

        if (in_array($role, ['admin', 'hr'])) {
            return User::where('organization_id', $user->organization_id)->pluck('id')->toArray();
        }

        if ($role === 'manager') {
            $directReportIds = User::where('organization_id', $user->organization_id)
                ->where('manager_id', $user->id)
                ->pluck('id')
                ->toArray();

            $subReportIds = User::where('organization_id', $user->organization_id)
                ->whereIn('manager_id', $directReportIds)
                ->pluck('id')
                ->toArray();

            return array_unique(array_merge($directReportIds, $subReportIds));
        }

        if ($role === 'team_leader') {
            return User::where('organization_id', $user->organization_id)
                ->where('manager_id', $user->id)
                ->pluck('id')
                ->toArray();
        }

        return [];
    }

    /**
     * Check if user is authorized to access the given document.
     */
    private function canAccessDocument(User $user, EmployeeDocument $doc): bool
    {
        if ((int) $doc->organization_id !== (int) $user->organization_id) {
            return false;
        }

        if ((int) $doc->user_id === (int) $user->id) {
            return true;
        }

        $role = $user->getCanonicalRole();
        if (in_array($role, ['admin', 'hr'])) {
            return true;
        }

        $subordinates = $this->getSubordinateUserIds($user);
        return in_array($doc->user_id, $subordinates);
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $role = $user->getCanonicalRole();

        $query = EmployeeDocument::where('organization_id', $user->organization_id)->with(['user.role']);

        if ($role === 'employee') {
            $query->where('user_id', $user->id);
        } elseif (in_array($role, ['manager', 'team_leader'])) {
            $subordinates = $this->getSubordinateUserIds($user);
            $allowedIds = array_merge([$user->id], $subordinates);
            $query->whereIn('user_id', $allowedIds);
        }

        if ($request->has('type') && $request->type !== '') {
            $query->where('type', $request->type);
        }

        if ($request->has('user_id') && $request->user_id !== '') {
            $targetId = (int) $request->user_id;
            if (in_array($role, ['admin', 'hr']) || $targetId === $user->id || in_array($targetId, $this->getSubordinateUserIds($user))) {
                $query->where('user_id', $targetId);
            }
        }

        $documents = $query->orderBy('created_at', 'desc')->get();
        return response()->json(['documents' => $documents]);
    }

    public function upload(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'title' => 'required|string|max:255',
            'type' => 'required|string|max:50',
            'file' => 'required|file|mimes:pdf,doc,docx,xls,xlsx,csv,txt,jpg,jpeg,png|max:15360', // 15MB max
            'user_id' => 'nullable|exists:users,id',
        ]);

        $targetUserId = $user->id;
        if ($request->filled('user_id') && in_array($user->getCanonicalRole(), ['admin', 'hr'])) {
            // Verify target user belongs to same organization
            $targetUser = User::where('organization_id', $user->organization_id)->where('id', $request->user_id)->first();
            if ($targetUser) {
                $targetUserId = $targetUser->id;
            }
        }

        $uploadedFile = $request->file('file');
        $safeFilename = Str::uuid()->toString() . '.' . $uploadedFile->getClientOriginalExtension();
        $storedPath = $uploadedFile->storeAs('documents/' . $user->organization_id, $safeFilename, 'local');

        $doc = EmployeeDocument::create([
            'organization_id' => $user->organization_id,
            'user_id' => $targetUserId,
            'title' => $request->title,
            'type' => $request->type,
            'file_url' => $storedPath,
        ]);

        AuditLog::create([
            'organization_id' => $user->organization_id,
            'actor_id' => $user->id,
            'action' => 'upload_document',
            'target_type' => EmployeeDocument::class,
            'target_id' => $doc->id,
            'payload' => ['title' => $doc->title, 'type' => $doc->type, 'target_user_id' => $targetUserId],
        ]);

        return response()->json([
            'message' => 'Document uploaded and securely saved to vault successfully!',
            'document' => $doc->load('user.role')
        ], 201);
    }

    public function download(Request $request, $id)
    {
        $user = $request->user();

        $doc = EmployeeDocument::where('organization_id', $user->organization_id)
            ->where('id', $id)
            ->first();

        if (!$doc) {
            return response()->json(['message' => 'Document not found'], 404);
        }

        if (!$this->canAccessDocument($user, $doc)) {
            return response()->json(['message' => 'Unauthorized: You do not have permission to access this document'], 403);
        }

        if (!Storage::disk('local')->exists($doc->file_url)) {
            return response()->json(['message' => 'The physical document file does not exist on storage.'], 404);
        }

        $ext = pathinfo($doc->file_url, PATHINFO_EXTENSION);
        $cleanTitle = Str::slug($doc->title) ?: 'document';
        $downloadName = "{$cleanTitle}.{$ext}";

        return Storage::disk('local')->download($doc->file_url, $downloadName);
    }

    public function view(Request $request, $id)
    {
        $user = $request->user();

        $doc = EmployeeDocument::where('organization_id', $user->organization_id)
            ->where('id', $id)
            ->first();

        if (!$doc) {
            return response()->json(['message' => 'Document not found'], 404);
        }

        if (!$this->canAccessDocument($user, $doc)) {
            return response()->json(['message' => 'Unauthorized: You do not have permission to view this document'], 403);
        }

        if (!Storage::disk('local')->exists($doc->file_url)) {
            return response()->json(['message' => 'The physical document file does not exist on storage.'], 404);
        }

        $ext = strtolower(pathinfo($doc->file_url, PATHINFO_EXTENSION));
        $mimeMap = [
            'pdf'  => 'application/pdf',
            'png'  => 'image/png',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'txt'  => 'text/plain; charset=utf-8',
            'csv'  => 'text/csv; charset=utf-8',
        ];

        $contentType = $mimeMap[$ext] ?? 'application/pdf';
        $filename = (Str::slug($doc->title) ?: 'document') . '.' . ($ext ?: 'pdf');

        return response(Storage::disk('local')->get($doc->file_url), 200, [
            'Content-Type' => $contentType,
            'Content-Disposition' => "inline; filename=\"{$filename}\"",
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-cache, private',
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $user = $request->user();

        $doc = EmployeeDocument::where('organization_id', $user->organization_id)
            ->where('id', $id)
            ->first();

        if (!$doc) {
            return response()->json(['message' => 'Document not found'], 404);
        }

        $role = $user->getCanonicalRole();
        $isOwner = (int) $doc->user_id === (int) $user->id;
        $isAdminOrHr = in_array($role, ['admin', 'hr']);
        $isManagerOrTlOfSubordinate = in_array($role, ['manager', 'team_leader']) && in_array($doc->user_id, $this->getSubordinateUserIds($user));

        if (!$isOwner && !$isAdminOrHr && !$isManagerOrTlOfSubordinate) {
            return response()->json(['message' => 'Unauthorized: Cannot delete this document'], 403);
        }

        if (Storage::disk('local')->exists($doc->file_url)) {
            Storage::disk('local')->delete($doc->file_url);
        }

        $doc->delete();

        AuditLog::create([
            'organization_id' => $user->organization_id,
            'actor_id' => $user->id,
            'action' => 'delete_document',
            'target_type' => EmployeeDocument::class,
            'target_id' => (int) $id,
        ]);

        return response()->json(['message' => 'Document removed successfully']);
    }
}
