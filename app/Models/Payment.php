<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_number',
        'contract_id',
        'tenant_id',
        'billing_period_start',
        'billing_period_end',
        'due_date',
        'amount_due',
        'interest_amount',
        'total_amount',
        'amount_paid',
        'balance',
        'payment_date',
        'payment_method',
        'payment_provider',
        'reference_number',
        'remarks',
        'status',
    ];

    protected $casts = [
        'billing_period_start' => 'date',
        'billing_period_end' => 'date',
        'due_date' => 'date',
        'payment_date' => 'date',
        'amount_due' => 'decimal:2',
        'interest_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'balance' => 'decimal:2',
    ];

    /**
     * Get the contract that owns the payment.
     */
    public function contract()
    {
        return $this->belongsTo(Contract::class);
    }

    /**
     * Get the tenant that owns the payment.
     */
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get the demand letters for this payment.
     */
    public function demandLetters()
    {
        return $this->hasMany(DemandLetter::class);
    }

    public function transactions()
    {
        return $this->hasMany(PaymentTransaction::class)->latest('paid_date');
    }

    /**
     * Check if payment is overdue.
     */
    public function isOverdue()
    {
        return $this->status === 'overdue' || 
               (Carbon::now()->isAfter($this->due_date) && $this->balance > 0);
    }

    /**
     * Calculate days overdue.
     */
    public function daysOverdue()
    {
        if (!$this->isOverdue()) {
            return 0;
        }
        return Carbon::now()->diffInDays($this->due_date);
    }

    /**
     * Calculate and apply interest for overdue payment.
     */
    public function calculateInterest()
    {
        if ($this->isOverdue() && $this->balance > 0) {
            $daysOverdue = $this->daysOverdue();
            $monthsOverdue = ceil($daysOverdue / 30);
            
            $contract = $this->contract;
            $interestRate = $contract->interest_rate / 100; // Convert percentage to decimal
            
            $this->interest_amount = $this->amount_due * $interestRate * $monthsOverdue;
            $this->total_amount = $this->amount_due + $this->interest_amount;
            $this->balance = $this->total_amount - $this->amount_paid;
            $this->status = 'overdue';
            $this->save();
        }
    }

    public function refreshStatusFromDueDate()
    {
        if ($this->amount_paid >= $this->total_amount) {
            $this->status = 'paid';
            return $this;
        }

        if (!$this->due_date) {
            if ($this->amount_paid > 0) {
                $this->status = 'partial';
            } else {
                $this->status = 'pending';
            }
            return $this;
        }

        $dueDate = Carbon::parse($this->due_date);
        if (Carbon::now()->isAfter($dueDate) && $this->amount_paid < $this->total_amount) {
            $this->status = 'overdue';
            return $this;
        }

        if ($this->amount_paid > 0) {
            $this->status = 'partial';
            return $this;
        }

        $this->status = 'pending';
        return $this;
    }

    public static function syncStatuses()
    {
        $payments = self::where(function ($query) {
            $query->whereNotNull('due_date')
                ->orWhereNotNull('payment_date');
        })->get();

        foreach ($payments as $payment) {
            $payment->refreshStatusFromDueDate();
            $payment->save();
        }
    }

    /**
     * Record a payment.
     */
    public function recordPayment($amount, $method, $referenceNumber = null, $remarks = null)
    {
        $this->amount_paid += $amount;
        $this->balance = $this->total_amount - $this->amount_paid;
        $this->payment_date = Carbon::now();
        $this->payment_method = $method;
        $this->reference_number = $referenceNumber;
        $this->remarks = $remarks;

        if ($this->balance <= 0) {
            $this->status = 'paid';
        } else {
            $this->status = 'partial';
        }

        $this->save();
    }
}
