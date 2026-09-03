<?php

namespace App\Http\Controllers;

use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

class ClientController extends Controller
{
    public function __construct()
    {
        $this->ensureTablesExist();
    }

    private function ensureTablesExist()
    {
        if (!Schema::hasTable('clients')) {
            Schema::create('clients', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->nullable()->constrained()->onDelete('cascade');
                $table->string('name');
                $table->string('company_name')->nullable();
                $table->string('email')->nullable();
                $table->string('phone')->nullable();
                $table->string('tax_number')->nullable();
                $table->text('address')->nullable();
                $table->string('city')->nullable();
                $table->string('state')->nullable();
                $table->string('postal_code')->nullable();
                $table->string('country')->default('India');
                $table->string('currency')->default('INR');
                $table->text('notes')->nullable();
                $table->string('status')->default('active');
                $table->timestamps();
            });

            // Seed default initial enterprise clients
            $defaultClients = [
                [
                    'name' => 'Rahul Singhania',
                    'company_name' => 'Acme Technologies Ltd',
                    'email' => 'accounts@acmetech.in',
                    'phone' => '+91 98250 11223',
                    'tax_number' => '24AAACA1234F1Z5',
                    'address' => 'Plot 42, GIDC Electronic Estate, Sector 25',
                    'city' => 'Gandhinagar',
                    'state' => 'Gujarat',
                    'postal_code' => '382024',
                    'country' => 'India',
                    'currency' => 'INR',
                    'status' => 'active',
                ],
                [
                    'name' => 'Priya Patel',
                    'company_name' => 'Nexus Cloud Solutions Pvt Ltd',
                    'email' => 'finance@nexuscloud.io',
                    'phone' => '+91 98795 44332',
                    'tax_number' => '24BBBBP5678Q1Z2',
                    'address' => '801, Synergy IT Park, Race Course Road',
                    'city' => 'Vadodara',
                    'state' => 'Gujarat',
                    'postal_code' => '390007',
                    'country' => 'India',
                    'currency' => 'INR',
                    'status' => 'active',
                ],
                [
                    'name' => 'Vikramaditya Rao',
                    'company_name' => 'Global Logistics & Retail Corp',
                    'email' => 'billing@globallogistics.com',
                    'phone' => '+91 99099 88776',
                    'tax_number' => '27AACCG9876K1Z9',
                    'address' => 'Level 12, Tower B, Peninsula Business Park, Lower Parel',
                    'city' => 'Mumbai',
                    'state' => 'Maharashtra',
                    'postal_code' => '400013',
                    'country' => 'India',
                    'currency' => 'INR',
                    'status' => 'active',
                ],
                [
                    'name' => 'Ananya Sen',
                    'company_name' => 'Zenith Health Diagnostics',
                    'email' => 'contact@zenithdiagnostics.com',
                    'phone' => '+91 91234 56780',
                    'tax_number' => '19AAECZ3344M1Z1',
                    'address' => 'Unit 304, Tech Park East, Salt Lake Sector V',
                    'city' => 'Kolkata',
                    'state' => 'West Bengal',
                    'postal_code' => '700091',
                    'country' => 'India',
                    'currency' => 'INR',
                    'status' => 'active',
                ],
            ];

            foreach ($defaultClients as $c) {
                Client::create(array_merge($c, ['organization_id' => 1]));
            }
        }
    }

    public function index(Request $request)
    {
        $this->ensureTablesExist();
        $user = $request->user();
        $query = Client::where('organization_id', $user->organization_id);

        if ($request->filled('search')) {
            $s = trim($request->search);
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', "%{$s}%")
                  ->orWhere('company_name', 'like', "%{$s}%")
                  ->orWhere('email', 'like', "%{$s}%")
                  ->orWhere('phone', 'like', "%{$s}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $clients = $query->orderBy('company_name', 'asc')->orderBy('name', 'asc')->get();

        return response()->json([
            'clients' => $clients,
            'total' => $clients->count(),
        ]);
    }

    public function store(Request $request)
    {
        $this->ensureTablesExist();
        $user = $request->user();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'company_name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'tax_number' => 'nullable|string|max:100',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:20',
            'country' => 'nullable|string|max:100',
            'currency' => 'nullable|string|max:10',
            'notes' => 'nullable|string',
        ]);

        $client = Client::create(array_merge($validated, [
            'organization_id' => $user->organization_id,
            'status' => 'active',
        ]));

        return response()->json([
            'message' => 'Client created successfully',
            'client' => $client,
        ], 201);
    }

    public function show(Request $request, $id)
    {
        $this->ensureTablesExist();
        $user = $request->user();
        $client = Client::where('organization_id', $user->organization_id)->with('invoices')->findOrFail($id);

        return response()->json([
            'client' => $client,
        ]);
    }

    public function update(Request $request, $id)
    {
        $this->ensureTablesExist();
        $user = $request->user();
        $client = Client::where('organization_id', $user->organization_id)->findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'company_name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'tax_number' => 'nullable|string|max:100',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:20',
            'country' => 'nullable|string|max:100',
            'currency' => 'nullable|string|max:10',
            'notes' => 'nullable|string',
            'status' => 'sometimes|in:active,inactive',
        ]);

        $client->update($validated);

        return response()->json([
            'message' => 'Client updated successfully',
            'client' => $client,
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $this->ensureTablesExist();
        $user = $request->user();
        $client = Client::where('organization_id', $user->organization_id)->findOrFail($id);

        $client->delete();

        return response()->json([
            'message' => 'Client removed successfully',
        ]);
    }
}
