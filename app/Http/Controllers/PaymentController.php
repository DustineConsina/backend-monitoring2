<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\Contract;
use App\Models\DemandLetter;
use App\Models\AuditLog;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class PaymentController extends Controller
{
    /**
     * Display a listing of payments
     */
    public function index(Request $request)
    {
        $query = Payment::with(['tenant.user', 'contract.rentalSpace']);

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('payment_number', 'like', "%{$search}%")
                    ->orWhereHas('tenant', function ($q) use ($search) {
                        $q->where('business_name', 'like', "%{$search}%")
                            ->orWhere('tenant_code', 'like', "%{$search}%");
                    })
                    ->orWhereHas('contract', function ($q) use ($search) {
                        $q->where('contract_number', 'like', "%{$search}%");
                    });
            });
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by tenant
        if ($request->has('tenant_id')) {
            $query->where('tenant_id', $request->tenant_id);
        }

        // Filter by contract
        if ($request->has('contract_id')) {
            $query->where('contract_id', $request->contract_id);
        }

        // Filter by date range
        if ($request->has('date_from')) {
            $query->where('due_date', '>=', $request->date_from);
        }
        if ($request->has('date_to')) {
            $query->where('due_date', '<=', $request->date_to);
        }

        // Sort
        $sortBy = $request->get('sort_by', 'due_date');
        $sortOrder = $request->get('sort_order', 'asc');
        $query->orderBy($sortBy, $sortOrder);

        $payments = $query->paginate($request->get('per_page', 15));

        AuditLog::log('view', 'Payment', null, 'Viewed payment list');

        return response()->json([
            'success' => true,
            'data' => $payments
        ]);
    }

    /**
     * Display the specified payment
     */
    public function show($id)
    {
        $payment = Payment::with([
            'tenant.user',
            'contract' => function($query) {
                $query->with('rentalSpace');
            },
            'demandLetters'
        ])->findOrFail($id);

        // Ensure financial fields are present and properly typed
        $payment->amount_due = (float)$payment->amount_due;
        $payment->interest_amount = (float)$payment->interest_amount;
        $payment->total_amount = (float)$payment->total_amount;
        $payment->amount_paid = (float)$payment->amount_paid;
        $payment->balance = (float)$payment->balance;

        AuditLog::log('view', 'Payment', $payment->id, "Viewed payment: {$payment->payment_number}");

        return response()->json([
            'success' => true,
            'data' => $payment
        ]);
    }

    /**
     * Get contracts with pending payments for dropdown in payment recording form
     */
    public function getPayableContracts(Request $request)
    {
        $contracts = Contract::with(['tenant.user', 'rentalSpace', 'payments'])
            ->where('status', 'active')
            ->has('payments') // Only contracts that have payments
            ->get()
            ->map(function ($contract) {
                // Get pending/overdue payments count
                $pendingPayments = $contract->payments()
                    ->whereIn('status', ['pending', 'overdue', 'partial'])
                    ->count();
                
                // Get outstanding balance
                $balance = $contract->payments()
                    ->where('balance', '>', 0)
                    ->sum('balance');
                
                return [
                    'id' => $contract->id,
                    'contract_number' => $contract->contract_number,
                    'tenant_name' => $contract->tenant->business_name ?? 'Unknown',
                    'rental_space' => $contract->rentalSpace->name ?? 'Unknown',
                    'pending_count' => $pendingPayments,
                    'outstanding_balance' => $balance,
                    'label' => "{$contract->contract_number} - {$contract->tenant->business_name} ({$pendingPayments} pending)"
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $contracts
        ]);
    }

    /**
     * Create a new payment
     */
    public function store(Request $request)
    {
        // Map camelCase to snake_case
        $data = $request->all();
        
        if (isset($data['contractId'])) {
            $data['contract_id'] = $data['contractId'];
            unset($data['contractId']);
        }
        
        if (isset($data['tenantId'])) {
            $data['tenant_id'] = $data['tenantId'];
            unset($data['tenantId']);
        }
        
        if (isset($data['dueDate'])) {
            $data['due_date'] = $data['dueDate'];
            unset($data['dueDate']);
        }
        
        if (isset($data['paidDate'])) {
            $data['payment_date'] = $data['paidDate'];
            unset($data['paidDate']);
        }
        
        if (isset($data['paymentMethod'])) {
            $data['payment_method'] = $data['paymentMethod'];
            unset($data['paymentMethod']);
        }
        
        if (isset($data['notes']) || isset($data['remarks'])) {
            $data['remarks'] = $data['notes'] ?? $data['remarks'] ?? null;
            unset($data['notes']);
        }
        
        // Use amount as amount_due if not specified
        if (isset($data['amount']) && !isset($data['amount_due'])) {
            $data['amount_due'] = $data['amount'];
            unset($data['amount']);
        }
        
        // Get contract to extract tenant_id if not provided
        if (!isset($data['tenant_id']) && isset($data['contract_id'])) {
            $contract = Contract::find($data['contract_id']);
            if ($contract) {
                $data['tenant_id'] = $contract->tenant_id;
            }
        }
        
        $validator = Validator::make($data, [
            'contract_id' => 'required|exists:contracts,id',
            'tenant_id' => 'required|exists:tenants,id',
            'amount_due' => 'required|numeric|min:0',
            'due_date' => 'required|date',
            'billing_period_start' => 'sometimes|date',
            'billing_period_end' => 'sometimes|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        // Create payment record with 3% interest on monthly rent
        $interestRate = 0.03; // 3%
        $interest = $data['amount_due'] * $interestRate;
        $totalWithInterest = $data['amount_due'] + $interest;
        
        // Generate unique payment number
        $year = date('Y');
        $lastPayment = Payment::where('payment_number', 'LIKE', 'PAY-' . $year . '-%')
            ->orderByRaw("CAST(SUBSTRING(payment_number, -6) AS UNSIGNED) DESC")
            ->first();
        
        if ($lastPayment) {
            $lastNumber = (int)substr($lastPayment->payment_number, -6);
            $nextNumber = $lastNumber + 1;
        } else {
            $nextNumber = 1;
        }
        
        $paymentNumber = 'PAY-' . $year . '-' . str_pad($nextNumber, 6, '0', STR_PAD_LEFT);
        
        // If payment_date is provided, it means payment was already made, so set amount_paid to total
        $isPaid = isset($data['payment_date']) && $data['payment_date'] !== null;
        $amountPaid = $isPaid ? $totalWithInterest : 0;
        $remainingBalance = $totalWithInterest - $amountPaid;

        // Default missing billing dates to the contract anniversary cycle.
        $billingContract = Contract::find($data['contract_id']);
        $billingPeriodStart = $data['billing_period_start']
            ?? Carbon::parse($billingContract->start_date ?? $data['due_date']);
        $billingPeriodStart = $data['billing_period_start']
            ?? Carbon::parse($billingPeriodStart);
        $billingPeriodEnd = $data['billing_period_end']
            ?? $billingPeriodStart->copy()->addMonth();
        
        $payment = Payment::create([
            'payment_number' => $paymentNumber,
            'contract_id' => $data['contract_id'],
            'tenant_id' => $data['tenant_id'],
            'amount_due' => $data['amount_due'],
            'interest_amount' => $interest,
            'due_date' => $data['due_date'],
            'billing_period_start' => $billingPeriodStart,
            'billing_period_end' => $billingPeriodEnd,
            'total_amount' => $totalWithInterest,
            'amount_paid' => $amountPaid,
            'balance' => $remainingBalance,
            'payment_method' => $data['payment_method'] ?? null,
            'remarks' => $data['remarks'] ?? null,
            'payment_date' => $data['payment_date'] ?? null,
            'status' => $isPaid ? 'paid' : 'pending',
        ]);

        AuditLog::log('create', 'Payment', $payment->id, "Created payment: {$payment->payment_number}");

        return response()->json([
            'success' => true,
            'message' => 'Payment created successfully',
            'data' => $payment->fresh(['tenant.user', 'contract.rentalSpace'])
        ], 201);
    }

    /**
     * Update a payment
     */
    public function update(Request $request, $id)
    {
        $payment = Payment::findOrFail($id);
        
        // Map camelCase to snake_case
        $data = $request->all();
        
        // NEVER allow due_date to be updated - it's auto-calculated based on contract start date
        if (isset($data['due_date'])) {
            unset($data['due_date']);
        }
        if (isset($data['dueDate'])) {
            unset($data['dueDate']);
        }
        
        // Handle amount from frontend (new format)
        if (isset($data['amount']) && !isset($data['amount_to_pay'])) {
            $data['amount_to_pay'] = $data['amount'];
            unset($data['amount']);
        }
        
        if (isset($data['amountPaid'])) {
            $data['amount_paid'] = $data['amountPaid'];
            unset($data['amountPaid']);
        }
        
        // Also handle amount_to_pay from frontend
        if (isset($data['amount_to_pay']) && !isset($data['amount_paid'])) {
            $data['amount_paid'] = $data['amount_to_pay'];
            unset($data['amount_to_pay']);
        }
        
        if (isset($data['paymentMethod'])) {
            $data['payment_method'] = $data['paymentMethod'];
            unset($data['paymentMethod']);
        }
        
        if (isset($data['referenceNumber'])) {
            $data['reference_number'] = $data['referenceNumber'];
            unset($data['referenceNumber']);
        }
        
        if (isset($data['paidDate'])) {
            $data['payment_date'] = $data['paidDate'];
            unset($data['paidDate']);
        }
        
        if (isset($data['notes']) || isset($data['remarks'])) {
            $data['remarks'] = $data['notes'] ?? $data['remarks'] ?? null;
            unset($data['notes']);
        }
        
        $validator = Validator::make($data, [
            'amount_paid' => 'sometimes|numeric|min:0',
            'status' => 'sometimes|in:pending,paid,partial,overdue',
            'payment_method' => 'sometimes|string',
            'reference_number' => 'sometimes|nullable|string|max:255',
            'remarks' => 'sometimes|nullable|string',
            'payment_date' => 'sometimes|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $oldValues = $payment->toArray();

        // Update amount paid if provided
        if (isset($data['amount_paid'])) {
            // Add the payment amount to existing total
            $payment->amount_paid += $data['amount_paid'];
            
            // Recalculate balance: total_amount (which includes 3% interest) - amount_paid
            $payment->balance = max(0, $payment->total_amount - $payment->amount_paid);
            
            // Update status based on total amount paid (including interest)
            if ($payment->amount_paid >= $payment->total_amount) {
                $payment->status = 'paid';
                $payment->payment_date = $payment->payment_date ?? now();
            } elseif ($payment->amount_paid > 0) {
                $payment->status = 'partial';
            } else {
                $payment->status = 'pending';
            }
            
            // Mark as overdue if past due date and not fully paid
            if ($payment->status !== 'paid' && now()->isAfter($payment->due_date)) {
                $payment->status = 'overdue';
            }
        }

        // Update status if provided (and not overridden by amount_paid logic above)
        if (isset($data['status']) && !isset($data['amount_paid'])) {
            $payment->status = $data['status'];
        }

        // Update other fields
        if (isset($data['payment_method'])) {
            $payment->payment_method = $data['payment_method'];
        }
        if (isset($data['reference_number'])) {
            $payment->reference_number = $data['reference_number'];
        } elseif (isset($data['amount_paid']) && isset($data['payment_method'])) {
            // Auto-generate reference number if recording payment without one
            $payment->reference_number = $this->generateReferenceNumber($data['payment_method']);
        }
        if (isset($data['remarks'])) {
            $payment->remarks = $data['remarks'];
        }
        if (isset($data['payment_date'])) {
            $payment->payment_date = $data['payment_date'];
        }

        $payment->save();

        AuditLog::log('update', 'Payment', $payment->id, "Updated payment: {$payment->payment_number}", $oldValues, $payment->toArray());

        return response()->json([
            'success' => true,
            'message' => 'Payment updated successfully',
            'data' => $payment->fresh(['tenant.user', 'contract.rentalSpace'])
        ]);
    }

    /**
     * Record a payment
     */
    public function recordPayment(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0',
            'payment_method' => 'required|in:cash,check,bank_transfer',
            'reference_number' => 'nullable|string|max:255',
            'remarks' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $payment = Payment::findOrFail($id);

        if ($payment->status === 'paid') {
            return response()->json([
                'success' => false,
                'message' => 'Payment already marked as paid'
            ], 422);
        }

        $oldValues = $payment->toArray();

        // Calculate interest if overdue
        if ($payment->isOverdue()) {
            $payment->calculateInterest();
        }

        if ($request->amount > $payment->balance) {
            return response()->json([
                'success' => false,
                'message' => 'Payment amount exceeds balance'
            ], 422);
        }

        // Generate reference number if not provided
        $referenceNumber = $request->reference_number ?: $this->generateReferenceNumber($request->payment_method);

        // Record the payment
        $payment->recordPayment(
            $request->amount,
            $request->payment_method,
            $referenceNumber,
            $request->remarks
        );

        // Create notification
        Notification::create([
            'user_id' => $payment->tenant->user_id,
            'type' => 'payment_received',
            'title' => 'Payment Received',
            'message' => "Payment of ₱" . number_format($request->amount, 2) . " received for {$payment->payment_number}",
            'data' => ['payment_id' => $payment->id],
        ]);

        AuditLog::log('update', 'Payment', $payment->id, "Recorded payment of ₱{$request->amount} for {$payment->payment_number}", $oldValues, $payment->toArray());

        return response()->json([
            'success' => true,
            'message' => 'Payment recorded successfully',
            'data' => $payment->fresh(['tenant.user', 'contract.rentalSpace'])
        ]);
    }

    /**
     * Update payment status manually
     */
    public function updateStatus(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:pending,paid,partial,overdue',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $payment = Payment::findOrFail($id);
        $oldStatus = $payment->status;
        $payment->status = $request->status;
        $payment->save();

        AuditLog::log('update', 'Payment', $payment->id, "Updated payment status from {$oldStatus} to {$request->status}", ['status' => $oldStatus], ['status' => $request->status]);

        return response()->json([
            'success' => true,
            'message' => 'Payment status updated successfully',
            'data' => $payment
        ]);
    }

    /**
     * Calculate overdue payments and apply interest
     */
    public function calculateOverduePayments()
    {
        $overduePayments = Payment::where('status', 'pending')
            ->where('due_date', '<', Carbon::now())
            ->get();

        $count = 0;
        foreach ($overduePayments as $payment) {
            $payment->calculateInterest();
            $count++;

            // Create notification if not already sent
            $existingNotification = Notification::where('user_id', $payment->tenant->user_id)
                ->where('type', 'payment_overdue')
                ->where('data->payment_id', $payment->id)
                ->whereDate('created_at', Carbon::today())
                ->exists();

            if (!$existingNotification) {
                Notification::create([
                    'user_id' => $payment->tenant->user_id,
                    'type' => 'payment_overdue',
                    'title' => 'Payment Overdue',
                    'message' => "Payment {$payment->payment_number} is overdue. Please pay immediately to avoid additional charges.",
                    'data' => ['payment_id' => $payment->id],
                ]);
            }
        }

        AuditLog::log('system', 'Payment', null, "Calculated interest for {$count} overdue payments");

        return response()->json([
            'success' => true,
            'message' => "Processed {$count} overdue payments",
            'count' => $count
        ]);
    }

    /**
     * Get payment summary by tenant
     */
    public function getTenantPaymentSummary($tenantId)
    {
        $summary = [
            'total_payments' => Payment::where('tenant_id', $tenantId)->count(),
            'paid_payments' => Payment::where('tenant_id', $tenantId)->where('status', 'paid')->count(),
            'pending_payments' => Payment::where('tenant_id', $tenantId)->where('status', 'pending')->count(),
            'overdue_payments' => Payment::where('tenant_id', $tenantId)->where('status', 'overdue')->count(),
            'total_paid' => Payment::where('tenant_id', $tenantId)->where('status', 'paid')->sum('amount_paid'),
            'total_balance' => Payment::where('tenant_id', $tenantId)->whereIn('status', ['pending', 'overdue', 'partial'])->sum('balance'),
            'total_interest_charged' => Payment::where('tenant_id', $tenantId)->where('status', 'overdue')->sum('interest_amount'),
        ];

        return response()->json([
            'success' => true,
            'data' => $summary
        ]);
    }

    /**
     * Get payment history
     */
    public function getPaymentHistory($tenantId)
    {
        $payments = Payment::with('contract.rentalSpace')
            ->where('tenant_id', $tenantId)
            ->orderBy('payment_date', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $payments
        ]);
    }

    /**
     * Get all demand letters for all contracts
     */
    public function getAllDemandLetters(Request $request)
    {
        $query = DemandLetter::with([
            'payment',
            'tenant',
            'contract.rentalSpace'
        ]);

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by tenant
        if ($request->has('tenant_id')) {
            $query->where('tenant_id', $request->tenant_id);
        }

        // Sort
        $sortBy = $request->get('sort_by', 'issued_date');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $demandLetters = $query->paginate($request->get('per_page', 20))->through(function ($letter) {
            return [
                'id' => $letter->id,
                'demandNumber' => $letter->demand_number,
                'contractId' => $letter->contract_id,
                'tenantId' => $letter->tenant_id,
                'paymentId' => $letter->payment_id,
                'outstandingBalance' => (float) $letter->outstanding_balance,
                'totalAmountDemanded' => (float) $letter->total_amount_demanded,
                'issuedDate' => $letter->issued_date->toIso8601String(),
                'dueDate' => $letter->due_date->toIso8601String(),
                'status' => $letter->status,
                'sentDate' => $letter->sent_date?->toIso8601String(),
                'emailSentTo' => $letter->email_sent_to,
                'remarks' => $letter->remarks,
                'tenant' => $letter->tenant ? [
                    'id' => $letter->tenant->id,
                    'name' => $letter->tenant->business_name,
                    'contactPerson' => $letter->tenant->contact_person,
                    'email' => $letter->tenant->email,
                ] : null,
                'contract' => $letter->contract ? [
                    'id' => $letter->contract->id,
                    'contractNumber' => $letter->contract->contract_number,
                    'rentalSpace' => $letter->contract->rentalSpace ? [
                        'id' => $letter->contract->rentalSpace->id,
                        'name' => $letter->contract->rentalSpace->name,
                    ] : null,
                ] : null,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $demandLetters
        ]);
    }

    /**
     * Get all demand letters for a contract
     */
    public function listDemandLetters($contractId)
    {
        $contract = Contract::findOrFail($contractId);
        
        $demandLetters = $contract->demandLetters()
            ->with('payment', 'tenant')
            ->orderBy('issued_date', 'desc')
            ->get()
            ->map(function ($letter) {
                return [
                    'id' => $letter->id,
                    'demand_number' => $letter->demand_number,
                    'issued_date' => $letter->issued_date->format('M d, Y'),
                    'due_date' => $letter->due_date->format('M d, Y'),
                    'outstanding_balance' => (float) $letter->outstanding_balance,
                    'total_amount_demanded' => (float) $letter->total_amount_demanded,
                    'status' => $letter->status,
                    'sent_date' => $letter->sent_date ? $letter->sent_date->format('M d, Y H:i') : null,
                    'days_remaining' => $letter->due_date->diffInDays(Carbon::now()),
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $demandLetters
        ]);
    }

    /**
     * Get contract payment summary with rental info
     */
    public function getContractPaymentSummary($contractId)
    {
        $contract = Contract::with('tenant', 'rentalSpace')->findOrFail($contractId);
        
        $payments = $contract->payments()->get();
        $totalOutstanding = $payments->where('status', '!=', 'paid')->sum('balance');
        $totalInterest = $payments->sum('interest_amount');

        // Calculate rent breakdown
        $baseRent = $contract->monthly_rental;
        $totalRentWithInterest = $baseRent * 1.03; // Base + 3% interest
        $interestComponent = $baseRent * 0.03;

        return response()->json([
            'success' => true,
            'data' => [
                'contract_number' => $contract->contract_number,
                'tenant_name' => $contract->tenant->business_name,
                'space_name' => $contract->rentalSpace->name,
                'base_monthly_rent' => (float) $baseRent,
                'interest_percentage' => 3,
                'interest_amount_per_month' => (float) $interestComponent,
                'monthly_billing_amount' => (float) $totalRentWithInterest,
                'billing_description' => 'Monthly rent includes 3% built-in interest',
                'lease_period' => "{$contract->start_date->format('M d, Y')} - {$contract->end_date->format('M d, Y')}",
                'total_payments' => $payments->count(),
                'paid_payments' => $payments->where('status', 'paid')->count(),
                'pending_payments' => $payments->where('status', 'pending')->count(),
                'overdue_payments' => $payments->where('status', 'overdue')->count(),
                'total_billed' => (float) $payments->sum('amount_due'),
                'total_interest_collected' => (float) $totalInterest,
                'total_paid' => (float) $payments->sum('amount_paid'),
                'total_outstanding' => (float) $totalOutstanding,
                'demand_letters_issued' => $contract->demandLetters()->count(),
            ]
        ]);
    }

    /**
     * Check if next month rent will have 3% penalty
     */
    private function hasPenaltyForNextMonth(Contract $contract)
    {
        // Get the current billing period based on contract anniversary
        $contractStart = $contract->start_date;
        $monthsElapsed = $contractStart->diffInMonths(Carbon::now());
        
        // Previous period ends on the current anniversary minus one month
        $previousPeriodEnd = $contractStart->copy()->addMonths($monthsElapsed);
        
        $previousPayment = Payment::where('contract_id', $contract->id)
            ->whereDate('billing_period_end', $previousPeriodEnd)
            ->first();

        return $previousPayment && ($previousPayment->status === 'overdue' || $previousPayment->balance > 0);
    }

    /**
     * Get next month's rent amount (with penalty if applicable)
     */
    private function getNextMonthRent(Contract $contract)
    {
        $baseRent = $contract->monthly_rental;
        
        if ($this->hasPenaltyForNextMonth($contract)) {
            return $baseRent + ($baseRent * 0.03); // Base + 3% penalty
        }
        
        return $baseRent;
    }

    /**
     * Download demand letter PDF
     */
    public function downloadDemandLetter($demandLetterId)
    {
        try {
            $demandLetter = DemandLetter::with([
                'payment.contract.rentalSpace',
                'tenant.user'
            ])->findOrFail($demandLetterId);

            $payment = $demandLetter->payment;
            $contract = $payment->contract;
            $tenant = $demandLetter->tenant;

            // Calculate days overdue
            $daysOverdue = Carbon::now()->diffInDays($demandLetter->payment->due_date);

            // Prepare data for PDF
            $data = [
                'demandNumber' => $demandLetter->demand_number,
                'tenantName' => $tenant->contact_person,
                'tenantCompany' => $tenant->business_name,
                'tenantAddress' => $tenant->business_address ?? 'Not provided',
                'tenantPhone' => $tenant->contact_number,
                'spaceName' => $contract->rentalSpace->name,
                'spaceCode' => $contract->rentalSpace->space_code,
                'billingPeriod' => $payment->billing_period_start->format('F d, Y') . ' to ' . $payment->billing_period_end->format('F d, Y'),
                'rentalAmount' => number_format($payment->amount_due, 2),
                'interestAmount' => number_format($payment->interest_amount, 2),
                'originalDueDate' => $payment->due_date->format('F d, Y'),
                'daysOverdue' => max(0, $daysOverdue),
                'totalAmountDemanded' => number_format($demandLetter->total_amount_demanded, 2),
                'issuedDate' => $demandLetter->issued_date->format('F d, Y'),
                'settlementDeadline' => $demandLetter->due_date->format('F d, Y'),
                'contractDate' => $contract->created_at->format('F d, Y'),
                'currentDate' => Carbon::now()->format('F d, Y'),
                'generatedDate' => Carbon::now()->format('F d, Y h:i A'),
            ];

            // Generate PDF
            $pdf = \PDF::loadView('demand-letters.letter', $data);
            
            // Return PDF to browser
            return $pdf->stream('demand-letter-' . $demandLetter->demand_number . '.pdf');
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate demand letter: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate unique reference number for payment
     */
    private function generateReferenceNumber($paymentMethod)
    {
        $timestamp = now()->format('YmdHis');
        $random = str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT);
        
        $prefix = match($paymentMethod) {
            'cash' => 'CASH',
            'check' => 'CHK',
            'bank_transfer' => 'BANK',
            default => 'PAY'
        };
        
        return "{$prefix}-{$timestamp}-{$random}";
    }
}

