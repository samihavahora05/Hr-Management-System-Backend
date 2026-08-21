<?php

namespace App\Http\Controllers;

use App\Models\HelpdeskTicket;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\Request;

class HelpdeskController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $role = $user->getCanonicalRole();

        $query = HelpdeskTicket::where('organization_id', $user->organization_id)->with(['requester', 'assignee']);

        if ($role === 'employee') {
            $query->where('requester_id', $user->id);
        }

        $tickets = $query->orderBy('created_at', 'desc')->get();
        return response()->json(['tickets' => $tickets]);
    }

    public function store(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'category' => 'required|string',
            'priority' => 'nullable|in:low,medium,high,urgent',
            'subject' => 'required|string',
            'description' => 'required|string',
        ]);

        $ticketCount = HelpdeskTicket::where('organization_id', $user->organization_id)->count() + 1;
        $ticketNumber = 'TICK-' . str_pad($ticketCount, 4, '0', STR_PAD_LEFT);

        $ticket = HelpdeskTicket::create([
            'organization_id' => $user->organization_id,
            'ticket_number' => $ticketNumber,
            'requester_id' => $user->id,
            'category' => $request->category,
            'priority' => $request->priority ?? 'medium',
            'subject' => $request->subject,
            'description' => $request->description,
            'status' => 'open',
        ]);

        return response()->json(['message' => 'HR Helpdesk ticket raised successfully', 'ticket' => $ticket], 201);
    }

    public function updateStatus(Request $request, $id)
    {
        $actor = $request->user();
        if (!in_array($actor->getCanonicalRole(), ['admin', 'hr'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'status' => 'required|in:open,in_progress,resolved,closed',
            'resolution_notes' => 'nullable|string',
        ]);

        $ticket = HelpdeskTicket::where('organization_id', $actor->organization_id)->where('id', $id)->first();
        if (!$ticket) {
            return response()->json(['message' => 'Ticket not found'], 404);
        }

        $ticket->status = $request->status;
        $ticket->assigned_to = $actor->id;
        if ($request->filled('resolution_notes')) {
            $ticket->resolution_notes = $request->resolution_notes;
        }
        $ticket->save();

        NotificationService::create(
            $actor->organization_id,
            $ticket->requester_id,
            'HR Helpdesk Ticket Updated',
            "Ticket {$ticket->ticket_number} status updated to " . strtoupper($request->status),
            'info'
        );

        return response()->json(['message' => 'Ticket updated successfully', 'ticket' => $ticket]);
    }
}
