<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\Contract;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CashierController extends Controller
{
    /**
     * Get today's collection summary
     */
    public function getTodaysCollection()
    {
        try {
            $today = Carbon::today();
            
            // Get today's recorded payments
            $payments = Payment::whereDate('payment_date', $today)
                ->where('status', 'paid')
                ->get();
            
            $totalCollected = $payments->sum('amount_paid');
            $paymentCount = $payments->count();
            
            return response()->json([
                'success' => true,
                'data' => [
                    'date' => $today->format('Y-m-d'),
                    'total_collected' => number_format($totalCollected, 2),
                    'payment_count' => $paymentCount,
                    'payments' => $payments->map(fn($p) => [
                        'id' => $p->id,
                        'payment_number' => $p->payment_number,
                        'contract_number' => $p->contract->contract_number,
                        'tenant' => $p->contract->tenant->contact_person,
                        'amount' => $p->amount_paid,
                        'method' => $p->payment_method,
                        'recorded_at' => $p->payment_date,
                    ])
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch today\'s collection: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get pending and overdue payments for collection
     */
    public function getCollectibles(Request $request)
    {
        try {
            $status = $request->get('status', 'all'); // all, pending, overdue, paid
            
            // Load contract with tenant details
            $query = Payment::with(['contract.tenant.user']);
            
            if ($status === 'paid') {
                $query->where('status', 'paid');
            } elseif ($status === 'overdue') {
                $query->where('status', 'overdue');
            } elseif ($status === 'pending') {
                $query->whereIn('status', ['pending', 'partial']);
            } else {
                // 'all' - include all statuses
                $query->whereIn('status', ['pending', 'overdue', 'partial', 'paid']);
            }
            
            $payments = $query->orderBy('due_date', 'asc')->get();
            
            // Group by days overdue for priority
            $grouped = $payments->mapToGroups(function($payment) {
                if ($payment->status === 'overdue') {
                    $daysOverdue = Carbon::parse($payment->due_date)->diffInDays(Carbon::today());
                    return [$daysOverdue > 30 ? 'critical' : ($daysOverdue > 7 ? 'urgent' : 'warning') => $payment];
                }
                return ['pending' => $payment];
            });
            
            // Map payments with guaranteed calculations
            $mappedPayments = $payments->map(function($p) {
                // amount_due should come from payment record, fallback to contract's monthly_rental
                $amountDue = floatval($p->amount_due ?? 0);
                if ($amountDue <= 0 && $p->contract) {
                    $amountDue = floatval($p->contract->monthly_rental ?? 0);
                }
                
                // Calculate interest if not set
                $interestAmount = floatval($p->interest_amount ?? 0);
                if ($interestAmount == 0 && $amountDue > 0) {
                    $interestAmount = $amountDue * 0.03;
                }
                
                // Calculate total if not set
                $totalAmount = floatval($p->total_amount ?? 0);
                if ($totalAmount == 0) {
                    $totalAmount = $amountDue + $interestAmount;
                }
                
                // Calculate balance
                $amountPaid = floatval($p->amount_paid ?? 0);
                $balance = $totalAmount - $amountPaid;
                
                // Get tenant name - try multiple locations
                $tenantName = 'N/A';
                if ($p->contract && $p->contract->tenant) {
                    $tenantName = $p->contract->tenant->contact_person ?? 
                                 $p->contract->tenant->user->name ?? 
                                 $p->contract->tenant->business_name ?? 
                                 'N/A';
                }
                
                return [
                    'id' => $p->id,
                    'payment_number' => $p->payment_number,
                    'contract_number' => $p->contract->contract_number ?? 'N/A',
                    'tenant' => $tenantName,
                    'amount_due' => $amountDue,
                    'interest' => $interestAmount,
                    'total' => $totalAmount,
                    'balance' => $balance,
                    'due_date' => $p->due_date,
                    'status' => $p->status,
                    'days_overdue' => $p->status === 'overdue' ? Carbon::parse($p->due_date)->diffInDays(Carbon::today()) : 0,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'critical' => (isset($grouped['critical']) ? $grouped['critical']->count() : 0) . ' payments (30+ days overdue)',
                    'urgent' => (isset($grouped['urgent']) ? $grouped['urgent']->count() : 0) . ' payments (7-30 days overdue)',
                    'warning' => (isset($grouped['warning']) ? $grouped['warning']->count() : 0) . ' payments (overdue)',
                    'pending' => (isset($grouped['pending']) ? $grouped['pending']->count() : 0) . ' payments (pending)',
                    'total_balance' => $mappedPayments->sum('balance'),
                    'payments' => $mappedPayments
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch collectibles: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Record a payment (same as PaymentController but for cashier)
     */
    public function recordPayment(Request $request, $id)
    {
        try {
            $validated = $request->validate([
                'amount' => 'required|numeric|min:0.01',
                'paid_date' => 'required|date',
                'payment_method' => 'required|in:cash,bank_transfer,e_wallet',
                'payment_provider' => 'nullable|string|max:100',
                'reference_number' => 'nullable|string',
                'remarks' => 'nullable|string',
            ]);

            if ($validated['payment_method'] !== 'cash' && empty($validated['payment_provider'])) {
                return response()->json(['success' => false, 'message' => 'Payment provider is required for non-cash payments.'], 422);
            }
            if ($validated['payment_method'] !== 'cash' && empty($validated['reference_number'])) {
                $label = $validated['payment_method'] === 'e_wallet' ? 'E-Wallet' : 'Bank Transfer';
                return response()->json(['success' => false, 'message' => "Reference number is required for {$label} payments."], 422);
            }

            $result = DB::transaction(function () use ($validated, $id) {
                $payment = Payment::whereKey($id)->lockForUpdate()->firstOrFail();
                $payment->refreshStatusFromDueDate();
                $payment->balance = max(0, (float) $payment->total_amount - (float) $payment->amount_paid);

                if ((float) $validated['amount'] > (float) $payment->balance) {
                    abort(response()->json(['success' => false, 'message' => 'Payment amount exceeds the remaining balance.'], 422));
                }

                $transaction = PaymentTransaction::create([
                    'payment_id' => $payment->id,
                    'amount' => $validated['amount'],
                    'paid_date' => $validated['paid_date'],
                    'payment_method' => $validated['payment_method'],
                    'payment_provider' => $validated['payment_provider'] ?? null,
                    'reference_number' => $validated['reference_number'] ?? null,
                    'recorded_by' => auth()->id(),
                    'remarks' => $validated['remarks'] ?? null,
                ]);

                $payment->amount_paid = PaymentTransaction::where('payment_id', $payment->id)->sum('amount');
                $payment->balance = max(0, (float) $payment->total_amount - (float) $payment->amount_paid);
                $payment->payment_date = $validated['paid_date'];
                $payment->payment_method = $validated['payment_method'];
                $payment->payment_provider = $validated['payment_method'] === 'cash' ? null : ($validated['payment_provider'] ?? null);
                $payment->reference_number = $validated['payment_method'] === 'cash' ? null : ($validated['reference_number'] ?? null);
                $payment->remarks = $validated['remarks'] ?? null;
                $payment->status = $payment->amount_paid >= $payment->total_amount
                    ? 'paid'
                    : ($payment->amount_paid > 0 ? 'partial' : ($payment->isOverdue() ? 'overdue' : 'pending'));
                $payment->save();

                return [$payment, $transaction];
            });

            [$payment, $transaction] = $result;

            return response()->json([
                'success' => true,
                'message' => 'Payment recorded successfully',
                'data' => $payment->fresh(['contract.tenant', 'transactions.recorder']),
                'transaction' => $transaction->load('recorder'),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to record payment: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate payment receipt
     */
    public function getReceipt($id)
    {
        try {
            $payment = Payment::with(['contract.tenant', 'contract.rentalSpace'])->findOrFail($id);

            $receiptData = [
                'receipt_number' => 'RCP-' . $payment->id . '-' . date('Ymd'),
                'payment_date' => $payment->payment_date ? Carbon::parse($payment->payment_date)->format('F d, Y H:i A') : Carbon::now()->format('F d, Y H:i A'),
                'contract_number' => $payment->contract->contract_number,
                'tenant_name' => $payment->contract->tenant->contact_person,
                'tenant_company' => $payment->contract->tenant->business_name,
                'space' => $payment->contract->rentalSpace->space_code,
                'payment_for' => $payment->billing_period_start . ' to ' . $payment->billing_period_end,
                'amount_due' => $payment->amount_due,
                'interest' => $payment->interest_amount,
                'total_due' => $payment->total_amount,
                'amount_paid' => $payment->amount_paid,
                'balance' => $payment->balance,
                'payment_method' => ucfirst(str_replace('_', ' ', $payment->payment_method)),
                'reference' => $payment->reference_number,
                'status' => ucfirst($payment->status),
            ];

            return response()->json([
                'success' => true,
                'data' => $receiptData
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate receipt: ' . $e->getMessage()
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
