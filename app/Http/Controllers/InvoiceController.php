<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Client;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Carbon\Carbon;

class InvoiceController extends Controller
{
    public function __construct()
    {
        $this->ensureTablesExist();
    }

    private function ensureTablesExist()
    {
        if (!Schema::hasTable('clients')) {
            (new ClientController())->index(new Request());
        }

        if (!Schema::hasTable('invoices')) {
            Schema::create('invoices', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->nullable()->constrained()->onDelete('cascade');
                $table->string('invoice_number')->index();
                $table->string('reference_number')->nullable();
                $table->foreignId('client_id')->nullable()->constrained('clients')->onDelete('set null');
                $table->string('client_name');
                $table->string('client_company_name')->nullable();
                $table->string('client_email')->nullable();
                $table->string('client_phone')->nullable();
                $table->text('client_address')->nullable();
                $table->string('client_city')->nullable();
                $table->string('client_state')->nullable();
                $table->string('client_postal_code')->nullable();
                $table->string('client_country')->default('India');
                $table->string('client_tax_number')->nullable();
                $table->date('invoice_date');
                $table->date('due_date')->nullable();
                $table->string('currency')->default('INR');
                $table->decimal('subtotal', 14, 2)->default(0);
                $table->string('discount_type')->default('fixed');
                $table->decimal('discount_value', 14, 2)->default(0);
                $table->decimal('discount_amount', 14, 2)->default(0);
                $table->decimal('tax_percentage', 5, 2)->default(0);
                $table->decimal('tax_amount', 14, 2)->default(0);
                $table->decimal('total_amount', 14, 2)->default(0);
                $table->decimal('paid_amount', 14, 2)->default(0);
                $table->decimal('balance_due', 14, 2)->default(0);
                $table->string('payment_status')->default('unpaid');
                $table->string('status')->default('sent');
                $table->string('payment_method')->nullable();
                $table->string('payment_reference')->nullable();
                $table->date('payment_date')->nullable();
                $table->text('notes')->nullable();
                $table->text('terms')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('invoice_items')) {
            Schema::create('invoice_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('invoice_id')->constrained('invoices')->onDelete('cascade');
                $table->string('item_name');
                $table->text('description')->nullable();
                $table->decimal('quantity', 10, 2)->default(1);
                $table->decimal('rate', 14, 2)->default(0);
                $table->decimal('discount', 14, 2)->default(0);
                $table->decimal('tax_percentage', 5, 2)->default(0);
                $table->decimal('total', 14, 2)->default(0);
                $table->timestamps();
            });

            // Seed sample real-world enterprise invoices
            $this->seedSampleInvoices();
        }
    }

    private function seedSampleInvoices()
    {
        $clients = Client::where('organization_id', 1)->get();
        if ($clients->isEmpty()) return;

        $c1 = $clients->where('company_name', 'Acme Technologies Ltd')->first() ?? $clients->first();
        $c2 = $clients->where('company_name', 'Nexus Cloud Solutions Pvt Ltd')->first() ?? $clients->first();
        $c3 = $clients->where('company_name', 'Global Logistics & Retail Corp')->first() ?? $clients->first();

        // 1. Paid Invoice
        $inv1 = Invoice::create([
            'organization_id' => 1,
            'invoice_number' => 'INV-000175',
            'reference_number' => 'PO-2026-8891',
            'client_id' => $c1->id,
            'client_name' => $c1->name,
            'client_company_name' => $c1->company_name,
            'client_email' => $c1->email,
            'client_phone' => $c1->phone,
            'client_address' => $c1->address,
            'client_city' => $c1->city,
            'client_state' => $c1->state,
            'client_postal_code' => $c1->postal_code,
            'client_country' => $c1->country,
            'client_tax_number' => $c1->tax_number,
            'invoice_date' => Carbon::now()->subDays(18)->toDateString(),
            'due_date' => Carbon::now()->subDays(3)->toDateString(),
            'currency' => 'INR',
            'subtotal' => 145000.00,
            'discount_type' => 'fixed',
            'discount_value' => 5000.00,
            'discount_amount' => 5000.00,
            'tax_percentage' => 18.00,
            'tax_amount' => 25200.00,
            'total_amount' => 165200.00,
            'paid_amount' => 165200.00,
            'balance_due' => 0.00,
            'payment_status' => 'paid',
            'status' => 'paid',
            'payment_method' => 'NEFT / Bank Transfer',
            'payment_reference' => 'TXN98721345678',
            'payment_date' => Carbon::now()->subDays(5)->toDateString(),
            'notes' => 'Thank you for your business. Annual Cloud Infrastructure & Maintenance Q3 retainer.',
            'terms' => 'Payment terms: Net 15 days. Subject to Vadodara jurisdiction.',
            'created_by' => 1,
        ]);

        InvoiceItem::create([
            'invoice_id' => $inv1->id,
            'item_name' => 'Enterprise Cloud Infrastructure Setup',
            'description' => 'Dedicated Kubernetes cluster configuration, multi-zone failover, and VPC peering.',
            'quantity' => 1,
            'rate' => 85000.00,
            'discount' => 0,
            'tax_percentage' => 18.00,
            'total' => 85000.00,
        ]);

        InvoiceItem::create([
            'invoice_id' => $inv1->id,
            'item_name' => 'DevOps Automation & CI/CD Pipeline',
            'description' => 'Automated deployment pipelines with vulnerability scanning and test suites.',
            'quantity' => 1,
            'rate' => 60000.00,
            'discount' => 5000.00,
            'tax_percentage' => 18.00,
            'total' => 55000.00,
        ]);

        // 2. Partially Paid Invoice
        $inv2 = Invoice::create([
            'organization_id' => 1,
            'invoice_number' => 'INV-000176',
            'reference_number' => 'PO-NEXUS-091',
            'client_id' => $c2->id,
            'client_name' => $c2->name,
            'client_company_name' => $c2->company_name,
            'client_email' => $c2->email,
            'client_phone' => $c2->phone,
            'client_address' => $c2->address,
            'client_city' => $c2->city,
            'client_state' => $c2->state,
            'client_postal_code' => $c2->postal_code,
            'client_country' => $c2->country,
            'client_tax_number' => $c2->tax_number,
            'invoice_date' => Carbon::now()->subDays(10)->toDateString(),
            'due_date' => Carbon::now()->addDays(15)->toDateString(),
            'currency' => 'INR',
            'subtotal' => 220000.00,
            'discount_type' => 'percentage',
            'discount_value' => 10.00,
            'discount_amount' => 22000.00,
            'tax_percentage' => 18.00,
            'tax_amount' => 35640.00,
            'total_amount' => 233640.00,
            'paid_amount' => 100000.00,
            'balance_due' => 133640.00,
            'payment_status' => 'partially_paid',
            'status' => 'sent',
            'payment_method' => 'RTGS Transfer',
            'payment_reference' => 'HDFC0098712398',
            'payment_date' => Carbon::now()->subDays(2)->toDateString(),
            'notes' => 'Milestone 1 completed (50% upfront received). Milestone 2 balance due upon final UAT signoff.',
            'terms' => 'Standard milestone payment schedule.',
            'created_by' => 1,
        ]);

        InvoiceItem::create([
            'invoice_id' => $inv2->id,
            'item_name' => 'Custom HRMS & Payroll Customization Module',
            'description' => 'Integration with internal ERP, biometric attendance API synchronization.',
            'quantity' => 1,
            'rate' => 180000.00,
            'discount' => 18000.00,
            'tax_percentage' => 18.00,
            'total' => 162000.00,
        ]);

        InvoiceItem::create([
            'invoice_id' => $inv2->id,
            'item_name' => 'Data Migration & Staff Training Workshops',
            'description' => '2-day hands-on admin onboarding and legacy employee record migration.',
            'quantity' => 2,
            'rate' => 20000.00,
            'discount' => 4000.00,
            'tax_percentage' => 18.00,
            'total' => 36000.00,
        ]);

        // 3. Unpaid Invoice (Current)
        $inv3 = Invoice::create([
            'organization_id' => 1,
            'invoice_number' => 'INV-000177',
            'reference_number' => 'GLR-PO-771',
            'client_id' => $c3->id,
            'client_name' => $c3->name,
            'client_company_name' => $c3->company_name,
            'client_email' => $c3->email,
            'client_phone' => $c3->phone,
            'client_address' => $c3->address,
            'client_city' => $c3->city,
            'client_state' => $c3->state,
            'client_postal_code' => $c3->postal_code,
            'client_country' => $c3->country,
            'client_tax_number' => $c3->tax_number,
            'invoice_date' => Carbon::now()->subDays(3)->toDateString(),
            'due_date' => Carbon::now()->addDays(12)->toDateString(),
            'currency' => 'INR',
            'subtotal' => 95000.00,
            'discount_type' => 'fixed',
            'discount_value' => 0,
            'discount_amount' => 0,
            'tax_percentage' => 18.00,
            'tax_amount' => 17100.00,
            'total_amount' => 112100.00,
            'paid_amount' => 0.00,
            'balance_due' => 112100.00,
            'payment_status' => 'unpaid',
            'status' => 'sent',
            'notes' => 'Monthly dedicated tech support & SLA management contract for August 2026.',
            'terms' => 'Payment due within 15 days of invoice date.',
            'created_by' => 1,
        ]);

        InvoiceItem::create([
            'invoice_id' => $inv3->id,
            'item_name' => '24/7 Priority SLA Technical Support',
            'description' => 'Dedicated engineering on-call support and monthly system health audits.',
            'quantity' => 1,
            'rate' => 95000.00,
            'discount' => 0,
            'tax_percentage' => 18.00,
            'total' => 95000.00,
        ]);
    }

    public function index(Request $request)
    {
        $this->ensureTablesExist();
        $user = $request->user();
        $orgId = $user->organization_id;

        $query = Invoice::where('organization_id', $orgId)->with(['client', 'items']);

        // Search Filter
        if ($request->filled('search')) {
            $s = trim($request->search);
            $query->where(function ($q) use ($s) {
                $q->where('invoice_number', 'like', "%{$s}%")
                  ->orWhere('reference_number', 'like', "%{$s}%")
                  ->orWhere('client_name', 'like', "%{$s}%")
                  ->orWhere('client_company_name', 'like', "%{$s}%")
                  ->orWhere('client_email', 'like', "%{$s}%");
            });
        }

        // Payment Status Filter
        if ($request->filled('payment_status') && $request->payment_status !== 'all') {
            $ps = $request->payment_status;
            if ($ps === 'overdue') {
                $query->where('balance_due', '>', 0)
                      ->where('due_date', '<', Carbon::today()->toDateString())
                      ->where('status', '!=', 'cancelled');
            } else {
                $query->where('payment_status', $ps);
            }
        }

        // Invoice Status Filter
        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // Date Range Filter
        if ($request->filled('start_date')) {
            $query->whereDate('invoice_date', '>=', $request->start_date);
        }
        if ($request->filled('end_date')) {
            $query->whereDate('invoice_date', '<=', $request->end_date);
        }

        // Client filter
        if ($request->filled('client_id')) {
            $query->where('client_id', $request->client_id);
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'invoice_date');
        $sortOrder = $request->get('sort_order', 'desc');
        $allowedSort = ['invoice_number', 'invoice_date', 'due_date', 'total_amount', 'paid_amount', 'balance_due', 'created_at'];
        if (in_array($sortBy, $allowedSort)) {
            $query->orderBy($sortBy, $sortOrder === 'asc' ? 'asc' : 'desc');
        } else {
            $query->orderBy('invoice_date', 'desc')->orderBy('id', 'desc');
        }

        $invoices = $query->get();

        // Calculate KPI summaries
        $allInvoices = Invoice::where('organization_id', $orgId)->get();
        $today = Carbon::today()->toDateString();

        $totalInvoices = $allInvoices->count();
        $totalAmount = floatval($allInvoices->where('status', '!=', 'cancelled')->sum('total_amount'));
        $paidAmount = floatval($allInvoices->sum('paid_amount'));
        $outstandingAmount = floatval($allInvoices->where('status', '!=', 'cancelled')->sum('balance_due'));
        $overdueAmount = floatval($allInvoices->filter(function ($inv) use ($today) {
            return $inv->status !== 'cancelled' && $inv->balance_due > 0 && $inv->due_date && $inv->due_date < $today;
        })->sum('balance_due'));

        $paidCount = $allInvoices->where('payment_status', 'paid')->count();
        $partiallyPaidCount = $allInvoices->where('payment_status', 'partially_paid')->count();
        $unpaidCount = $allInvoices->where('payment_status', 'unpaid')->count();
        $overdueCount = $allInvoices->filter(function ($inv) use ($today) {
            return $inv->status !== 'cancelled' && $inv->balance_due > 0 && $inv->due_date && $inv->due_date < $today;
        })->count();

        return response()->json([
            'invoices' => $invoices,
            'summary' => [
                'total_invoices' => $totalInvoices,
                'total_amount' => $totalAmount,
                'paid_amount' => $paidAmount,
                'outstanding_amount' => $outstandingAmount,
                'overdue_amount' => $overdueAmount,
                'paid_count' => $paidCount,
                'partially_paid_count' => $partiallyPaidCount,
                'unpaid_count' => $unpaidCount,
                'overdue_count' => $overdueCount,
            ],
        ]);
    }

    public function getNextNumber(Request $request)
    {
        $this->ensureTablesExist();
        $user = $request->user();
        $latest = Invoice::where('organization_id', $user->organization_id)
            ->orderBy('id', 'desc')
            ->first();

        if (!$latest || !preg_match('/INV-(\d+)/', $latest->invoice_number, $matches)) {
            $nextSeq = 178;
        } else {
            $nextSeq = intval($matches[1]) + 1;
        }

        $nextNumber = 'INV-' . str_pad($nextSeq, 6, '0', STR_PAD_LEFT);

        return response()->json([
            'next_invoice_number' => $nextNumber,
        ]);
    }

    public function store(Request $request)
    {
        $this->ensureTablesExist();
        $user = $request->user();
        $orgId = $user->organization_id;

        $validated = $request->validate([
            'invoice_number' => 'required|string|max:100',
            'reference_number' => 'nullable|string|max:100',
            'client_id' => 'nullable|exists:clients,id',
            'client_name' => 'required|string|max:255',
            'client_company_name' => 'nullable|string|max:255',
            'client_email' => 'nullable|email|max:255',
            'client_phone' => 'nullable|string|max:50',
            'client_tax_number' => 'nullable|string|max:100',
            'client_address' => 'nullable|string',
            'client_city' => 'nullable|string|max:100',
            'client_state' => 'nullable|string|max:100',
            'client_postal_code' => 'nullable|string|max:20',
            'client_country' => 'nullable|string|max:100',
            'invoice_date' => 'required|date',
            'due_date' => 'nullable|date',
            'currency' => 'nullable|string|max:10',
            'discount_type' => 'nullable|in:fixed,percentage',
            'discount_value' => 'nullable|numeric|min:0',
            'tax_percentage' => 'nullable|numeric|min:0|max:100',
            'paid_amount' => 'nullable|numeric|min:0',
            'status' => 'nullable|in:draft,sent,paid,cancelled',
            'payment_method' => 'nullable|string|max:100',
            'payment_reference' => 'nullable|string|max:100',
            'payment_date' => 'nullable|date',
            'notes' => 'nullable|string',
            'terms' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.item_name' => 'required|string|max:255',
            'items.*.description' => 'nullable|string',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.rate' => 'required|numeric|min:0',
            'items.*.discount' => 'nullable|numeric|min:0',
            'items.*.tax_percentage' => 'nullable|numeric|min:0|max:100',
        ]);

        return DB::transaction(function () use ($validated, $user, $orgId) {
            // Calculate Item Totals & Subtotal
            $subtotal = 0;
            $itemsData = [];

            foreach ($validated['items'] as $item) {
                $qty = floatval($item['quantity'] ?? 1);
                $rate = floatval($item['rate'] ?? 0);
                $disc = floatval($item['discount'] ?? 0);
                $itemTotal = max(0, ($qty * $rate) - $disc);
                $subtotal += $itemTotal;

                $itemsData[] = [
                    'item_name' => $item['item_name'],
                    'description' => $item['description'] ?? null,
                    'quantity' => $qty,
                    'rate' => $rate,
                    'discount' => $disc,
                    'tax_percentage' => floatval($item['tax_percentage'] ?? 0),
                    'total' => $itemTotal,
                ];
            }

            // Calculate Invoice-Level Discount
            $discType = $validated['discount_type'] ?? 'fixed';
            $discVal = floatval($validated['discount_value'] ?? 0);
            $discAmount = $discType === 'percentage' ? ($subtotal * ($discVal / 100)) : $discVal;
            $discAmount = min($subtotal, max(0, $discAmount));

            // Calculate Tax
            $taxPercent = floatval($validated['tax_percentage'] ?? 0);
            $taxable = max(0, $subtotal - $discAmount);
            $taxAmount = $taxable * ($taxPercent / 100);

            // Grand Total & Balances
            $totalAmount = round($taxable + $taxAmount, 2);
            $paidAmount = floatval($validated['paid_amount'] ?? 0);
            $balanceDue = max(0, round($totalAmount - $paidAmount, 2));

            // Automatic Payment Status Determination
            $paymentStatus = 'unpaid';
            if ($paidAmount >= $totalAmount && $totalAmount > 0) {
                $paymentStatus = 'paid';
            } elseif ($paidAmount > 0 && $paidAmount < $totalAmount) {
                $paymentStatus = 'partially_paid';
            }

            $invStatus = $validated['status'] ?? 'sent';
            if ($paymentStatus === 'paid' && $invStatus !== 'cancelled') {
                $invStatus = 'paid';
            }

            $invoice = Invoice::create([
                'organization_id' => $orgId,
                'invoice_number' => $validated['invoice_number'],
                'reference_number' => $validated['reference_number'] ?? null,
                'client_id' => $validated['client_id'] ?? null,
                'client_name' => $validated['client_name'],
                'client_company_name' => $validated['client_company_name'] ?? null,
                'client_email' => $validated['client_email'] ?? null,
                'client_phone' => $validated['client_phone'] ?? null,
                'client_tax_number' => $validated['client_tax_number'] ?? null,
                'client_address' => $validated['client_address'] ?? null,
                'client_city' => $validated['client_city'] ?? null,
                'client_state' => $validated['client_state'] ?? null,
                'client_postal_code' => $validated['client_postal_code'] ?? null,
                'client_country' => $validated['client_country'] ?? 'India',
                'invoice_date' => $validated['invoice_date'],
                'due_date' => $validated['due_date'] ?? null,
                'currency' => $validated['currency'] ?? 'INR',
                'subtotal' => $subtotal,
                'discount_type' => $discType,
                'discount_value' => $discVal,
                'discount_amount' => $discAmount,
                'tax_percentage' => $taxPercent,
                'tax_amount' => $taxAmount,
                'total_amount' => $totalAmount,
                'paid_amount' => $paidAmount,
                'balance_due' => $balanceDue,
                'payment_status' => $paymentStatus,
                'status' => $invStatus,
                'payment_method' => $validated['payment_method'] ?? null,
                'payment_reference' => $validated['payment_reference'] ?? null,
                'payment_date' => $validated['payment_date'] ?? ($paidAmount > 0 ? Carbon::today()->toDateString() : null),
                'notes' => $validated['notes'] ?? null,
                'terms' => $validated['terms'] ?? null,
                'created_by' => $user->id,
            ]);

            foreach ($itemsData as $it) {
                $invoice->items()->create($it);
            }

            return response()->json([
                'message' => "Invoice {$invoice->invoice_number} created successfully!",
                'invoice' => $invoice->load(['items', 'client']),
            ], 201);
        });
    }

    public function show(Request $request, $id)
    {
        $this->ensureTablesExist();
        $user = $request->user();
        $invoice = Invoice::where('organization_id', $user->organization_id)
            ->with(['items', 'client', 'creator'])
            ->findOrFail($id);

        $org = Organization::find($user->organization_id);
        $settings = $org ? $org->settings : [];

        $companyInfo = [
            'name' => $org->name ?? 'Blueboxx DA',
            'code' => $org->code ?? 'BLUEBOXX',
            'logo' => $settings['organization_logo'] ?? $settings['logo_url'] ?? '/images/logoblue.png',
            'icon_logo' => $settings['organization_icon_logo'] ?? $settings['icon_logo_url'] ?? '/images/Boxxlogo.png',
            'address' => '02, India Bulls Mall, Jetalpur Road',
            'city' => 'Vadodara',
            'state' => 'Gujarat',
            'postal_code' => '390022',
            'country' => 'India',
            'email' => 'contact@blueboxx.in',
            'phone' => '9023512853',
            'website' => 'http://www.blueboxx.in/',
            'gst_number' => '24AAACB9012F1Z8',
            'pan_number' => 'AAACB9012F',
            'bank_details' => [
                'bank_name' => 'HDFC Bank Ltd',
                'account_name' => 'BLUEBOXX ENTERPRISE PVT LTD',
                'account_number' => '50200088912345',
                'ifsc_code' => 'HDFC0000123',
                'branch' => 'Akota Branch, Vadodara',
            ]
        ];

        return response()->json([
            'invoice' => $invoice,
            'company' => $companyInfo,
        ]);
    }

    public function update(Request $request, $id)
    {
        $this->ensureTablesExist();
        $user = $request->user();
        $orgId = $user->organization_id;
        $invoice = Invoice::where('organization_id', $orgId)->findOrFail($id);

        $validated = $request->validate([
            'invoice_number' => 'required|string|max:100',
            'reference_number' => 'nullable|string|max:100',
            'client_id' => 'nullable|exists:clients,id',
            'client_name' => 'required|string|max:255',
            'client_company_name' => 'nullable|string|max:255',
            'client_email' => 'nullable|email|max:255',
            'client_phone' => 'nullable|string|max:50',
            'client_tax_number' => 'nullable|string|max:100',
            'client_address' => 'nullable|string',
            'client_city' => 'nullable|string|max:100',
            'client_state' => 'nullable|string|max:100',
            'client_postal_code' => 'nullable|string|max:20',
            'client_country' => 'nullable|string|max:100',
            'invoice_date' => 'required|date',
            'due_date' => 'nullable|date',
            'currency' => 'nullable|string|max:10',
            'discount_type' => 'nullable|in:fixed,percentage',
            'discount_value' => 'nullable|numeric|min:0',
            'tax_percentage' => 'nullable|numeric|min:0|max:100',
            'paid_amount' => 'nullable|numeric|min:0',
            'status' => 'nullable|in:draft,sent,paid,cancelled',
            'payment_method' => 'nullable|string|max:100',
            'payment_reference' => 'nullable|string|max:100',
            'payment_date' => 'nullable|date',
            'notes' => 'nullable|string',
            'terms' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.item_name' => 'required|string|max:255',
            'items.*.description' => 'nullable|string',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.rate' => 'required|numeric|min:0',
            'items.*.discount' => 'nullable|numeric|min:0',
            'items.*.tax_percentage' => 'nullable|numeric|min:0|max:100',
        ]);

        return DB::transaction(function () use ($validated, $invoice) {
            $subtotal = 0;
            $itemsData = [];

            foreach ($validated['items'] as $item) {
                $qty = floatval($item['quantity'] ?? 1);
                $rate = floatval($item['rate'] ?? 0);
                $disc = floatval($item['discount'] ?? 0);
                $itemTotal = max(0, ($qty * $rate) - $disc);
                $subtotal += $itemTotal;

                $itemsData[] = [
                    'item_name' => $item['item_name'],
                    'description' => $item['description'] ?? null,
                    'quantity' => $qty,
                    'rate' => $rate,
                    'discount' => $disc,
                    'tax_percentage' => floatval($item['tax_percentage'] ?? 0),
                    'total' => $itemTotal,
                ];
            }

            $discType = $validated['discount_type'] ?? 'fixed';
            $discVal = floatval($validated['discount_value'] ?? 0);
            $discAmount = $discType === 'percentage' ? ($subtotal * ($discVal / 100)) : $discVal;
            $discAmount = min($subtotal, max(0, $discAmount));

            $taxPercent = floatval($validated['tax_percentage'] ?? 0);
            $taxable = max(0, $subtotal - $discAmount);
            $taxAmount = $taxable * ($taxPercent / 100);

            $totalAmount = round($taxable + $taxAmount, 2);
            $paidAmount = floatval($validated['paid_amount'] ?? 0);
            $balanceDue = max(0, round($totalAmount - $paidAmount, 2));

            $paymentStatus = 'unpaid';
            if ($paidAmount >= $totalAmount && $totalAmount > 0) {
                $paymentStatus = 'paid';
            } elseif ($paidAmount > 0 && $paidAmount < $totalAmount) {
                $paymentStatus = 'partially_paid';
            }

            $invStatus = $validated['status'] ?? $invoice->status;
            if ($paymentStatus === 'paid' && $invStatus !== 'cancelled') {
                $invStatus = 'paid';
            }

            $invoice->update([
                'invoice_number' => $validated['invoice_number'],
                'reference_number' => $validated['reference_number'] ?? null,
                'client_id' => $validated['client_id'] ?? null,
                'client_name' => $validated['client_name'],
                'client_company_name' => $validated['client_company_name'] ?? null,
                'client_email' => $validated['client_email'] ?? null,
                'client_phone' => $validated['client_phone'] ?? null,
                'client_tax_number' => $validated['client_tax_number'] ?? null,
                'client_address' => $validated['client_address'] ?? null,
                'client_city' => $validated['client_city'] ?? null,
                'client_state' => $validated['client_state'] ?? null,
                'client_postal_code' => $validated['client_postal_code'] ?? null,
                'client_country' => $validated['client_country'] ?? 'India',
                'invoice_date' => $validated['invoice_date'],
                'due_date' => $validated['due_date'] ?? null,
                'currency' => $validated['currency'] ?? 'INR',
                'subtotal' => $subtotal,
                'discount_type' => $discType,
                'discount_value' => $discVal,
                'discount_amount' => $discAmount,
                'tax_percentage' => $taxPercent,
                'tax_amount' => $taxAmount,
                'total_amount' => $totalAmount,
                'paid_amount' => $paidAmount,
                'balance_due' => $balanceDue,
                'payment_status' => $paymentStatus,
                'status' => $invStatus,
                'payment_method' => $validated['payment_method'] ?? $invoice->payment_method,
                'payment_reference' => $validated['payment_reference'] ?? $invoice->payment_reference,
                'payment_date' => $validated['payment_date'] ?? $invoice->payment_date,
                'notes' => $validated['notes'] ?? null,
                'terms' => $validated['terms'] ?? null,
            ]);

            // Sync items
            $invoice->items()->delete();
            foreach ($itemsData as $it) {
                $invoice->items()->create($it);
            }

            return response()->json([
                'message' => "Invoice {$invoice->invoice_number} updated successfully!",
                'invoice' => $invoice->load(['items', 'client']),
            ]);
        });
    }

    public function destroy(Request $request, $id)
    {
        $this->ensureTablesExist();
        $user = $request->user();
        $invoice = Invoice::where('organization_id', $user->organization_id)->findOrFail($id);

        $invNum = $invoice->invoice_number;
        $invoice->delete();

        return response()->json([
            'message' => "Invoice {$invNum} deleted successfully.",
        ]);
    }

    public function recordPayment(Request $request, $id)
    {
        $this->ensureTablesExist();
        $user = $request->user();
        $invoice = Invoice::where('organization_id', $user->organization_id)->findOrFail($id);

        $validated = $request->validate([
            'paid_amount' => 'required|numeric|min:0.01',
            'payment_method' => 'nullable|string|max:100',
            'payment_reference' => 'nullable|string|max:100',
            'payment_date' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        $newPayment = floatval($validated['paid_amount']);
        $totalPaid = floatval($invoice->paid_amount) + $newPayment;
        $totalAmount = floatval($invoice->total_amount);
        $balanceDue = max(0, round($totalAmount - $totalPaid, 2));

        $paymentStatus = 'unpaid';
        if ($totalPaid >= $totalAmount && $totalAmount > 0) {
            $paymentStatus = 'paid';
        } elseif ($totalPaid > 0 && $totalPaid < $totalAmount) {
            $paymentStatus = 'partially_paid';
        }

        $invStatus = $invoice->status;
        if ($paymentStatus === 'paid' && $invStatus !== 'cancelled') {
            $invStatus = 'paid';
        }

        $invoice->update([
            'paid_amount' => $totalPaid,
            'balance_due' => $balanceDue,
            'payment_status' => $paymentStatus,
            'status' => $invStatus,
            'payment_method' => $validated['payment_method'] ?? $invoice->payment_method ?? 'Bank Transfer',
            'payment_reference' => $validated['payment_reference'] ?? $invoice->payment_reference,
            'payment_date' => $validated['payment_date'] ?? Carbon::today()->toDateString(),
            'notes' => $validated['notes'] ? ($invoice->notes ? $invoice->notes . "\nPayment Note: " . $validated['notes'] : "Payment Note: " . $validated['notes']) : $invoice->notes,
        ]);

        return response()->json([
            'message' => "Payment of ₹" . number_format($newPayment, 2) . " recorded for {$invoice->invoice_number}!",
            'invoice' => $invoice->load(['items', 'client']),
        ]);
    }
}
