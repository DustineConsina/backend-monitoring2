<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Payment;
use Carbon\Carbon;

class PaymentService
{
    /**
     * Generate monthly payment for a contract.
     * Monthly rent includes built-in 3% interest.
     */
    public function generateMonthlyPayment(Contract $contract, Carbon $billingDate)
    {
        // Determine which contract billing cycle we're in.
        $contractStart = $contract->start_date;
        $monthsElapsed = $contractStart->diffInMonths($billingDate);
        
        // Each billing period follows the contract anniversary
        // (e.g., Aug 20-Sep 20).
        $billingPeriodStart = $contractStart->copy()->addMonths($monthsElapsed);
        $billingPeriodEnd = $contractStart->copy()->addMonths($monthsElapsed + 1);
        
        // Check if payment already exists for this billing period
        $existingPayment = Payment::where('contract_id', $contract->id)
            ->whereDate('billing_period_start', $billingPeriodStart)
            ->first();

        if ($existingPayment) {
            return $existingPayment;
        }

        // Calculate base rent with 3% interest included
        $baseRent = $contract->monthly_rental;
        $interestAmount = $baseRent * 0.03; // 3% of monthly rent
        $monthlyRentWithInterest = $baseRent + $interestAmount;

        // Due date is one month from contract start date (on the anniversary)
        // e.g., contract starts Jan 15, due date is Feb 15, Mar 15, etc.
        $dueDate = $contractStart->copy()->addMonths($monthsElapsed + 1);

        // Get outstanding balance from previous month
        $previousPeriodEnd = $billingPeriodStart->copy()->subDay();
        $previousPayment = Payment::where('contract_id', $contract->id)
            ->whereDate('billing_period_end', $previousPeriodEnd)
            ->first();

        $previousBalance = $previousPayment ? $previousPayment->balance : 0;

        // Create new payment record
        $payment = Payment::create([
            'payment_number' => $this->generatePaymentNumber($contract),
            'contract_id' => $contract->id,
            'tenant_id' => $contract->tenant_id,
            'billing_period_start' => $billingPeriodStart,
            'billing_period_end' => $billingPeriodEnd,
            'amount_due' => $monthlyRentWithInterest,
            'interest_amount' => $interestAmount,
            'total_amount' => $monthlyRentWithInterest + $previousBalance,
            'amount_paid' => 0,
            'balance' => $monthlyRentWithInterest + $previousBalance,
            'status' => 'pending',
        ]);

        // Set due_date directly (not mass-assignable to prevent editing)
        $payment->due_date = $dueDate;
        $payment->save();

        return $payment;
    }

    /**
     * Record payment for a billing period.
     */
    public function recordPayment(Payment $payment, $amountPaid, $paymentMethod, $referenceNumber = null)
    {
        $payment->amount_paid += $amountPaid;
        $payment->balance = $payment->total_amount - $payment->amount_paid;

        if ($payment->balance <= 0) {
            $payment->status = 'paid';
            $payment->payment_date = Carbon::now();
        } else {
            $payment->status = 'partial';
        }

        $payment->payment_method = $paymentMethod;
        $payment->reference_number = $referenceNumber;
        $payment->save();

        return $payment;
    }

    /**
     * Generate unique payment number.
     */
    private function generatePaymentNumber(Contract $contract)
    {
        $count = Payment::where('contract_id', $contract->id)->count() + 1;
        return 'PAY-' . $contract->contract_number . '-' . str_pad($count, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Get all overdue payments for a contract.
     */
    public function getOverduePayments(Contract $contract)
    {
        return $contract->payments()
            ->where('status', '!=', 'paid')
            ->where('due_date', '<', Carbon::now())
            ->get();
    }

    /**
     * Calculate total outstanding balance for a contract.
     */
    public function getTotalOutstandingBalance(Contract $contract)
    {
        return $contract->payments()
            ->where('status', '!=', 'paid')
            ->sum('balance');
    }

    /**
     * Get payment summary for a contract.
     */
    public function getPaymentSummary(Contract $contract)
    {
        $payments = $contract->payments()->get();

        return [
            'total_billed' => $payments->sum('total_amount'),
            'total_paid' => $payments->sum('amount_paid'),
            'total_outstanding' => $payments->sum('balance'),
            'number_of_payments' => $payments->count(),
            'paid_payments' => $payments->where('status', 'paid')->count(),
            'overdue_payments' => $payments->where('status', 'overdue')->count(),
        ];
    }
}
