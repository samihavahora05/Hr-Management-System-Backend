<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\Request;

class AssetController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $role = $user->getCanonicalRole();

        $query = Asset::where('organization_id', $user->organization_id)->with('assignedEmployee');

        if ($role === 'employee') {
            $query->where('assigned_to', $user->id);
        }

        $assets = $query->orderBy('name', 'asc')->get();
        return response()->json(['assets' => $assets]);
    }

    public function store(Request $request)
    {
        $actor = $request->user();
        if (!in_array($actor->getCanonicalRole(), ['admin', 'hr'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'asset_code' => 'required|string|unique:assets,asset_code',
            'name' => 'required|string',
            'category' => 'required|string',
            'serial_number' => 'nullable|string',
        ]);

        $asset = Asset::create([
            'organization_id' => $actor->organization_id,
            'asset_code' => $request->asset_code,
            'name' => $request->name,
            'category' => $request->category,
            'serial_number' => $request->serial_number,
            'status' => 'available',
        ]);

        return response()->json(['message' => 'Asset registered successfully', 'asset' => $asset], 201);
    }

    public function assign(Request $request, $id)
    {
        $actor = $request->user();
        if (!in_array($actor->getCanonicalRole(), ['admin', 'hr'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'assigned_to' => 'required|exists:users,id',
        ]);

        $asset = Asset::where('organization_id', $actor->organization_id)->where('id', $id)->first();
        if (!$asset) {
            return response()->json(['message' => 'Asset not found'], 404);
        }

        $asset->assigned_to = $request->assigned_to;
        $asset->assigned_at = now();
        $asset->status = 'assigned';
        $asset->save();

        NotificationService::create(
            $actor->organization_id,
            $request->assigned_to,
            'Company Asset Assigned',
            "Asset {$asset->name} ({$asset->asset_code}) has been assigned to you.",
            'info'
        );

        return response()->json(['message' => 'Asset assigned successfully', 'asset' => $asset]);
    }
}
