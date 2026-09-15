<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;

class Contract extends Model
{
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::forceDeleted(function (self $contract) {
            $contract->payments()->delete();

            if ($contract->rental_space_id) {
                $hasOtherActiveContract = self::withTrashed()
                    ->where('rental_space_id', $contract->rental_space_id)
                    ->where('id', '!=', $contract->id)
                    ->whereIn('status', ['active', 'for_renewal', 'pending', 'renewed'])
                    ->exists();

                if (!$hasOtherActiveContract && $contract->rentalSpace) {
                    $contract->rentalSpace->update(['status' => 'available']);
                }
            }

            RentalSpace::syncOccupancyStatuses();
        });
    }

    protected $fillable = [
        'contract_number',
        'tenant_id',
        'rental_space_id',
        'start_date',
        'end_date',
        'duration_months',
        'monthly_rental',
        'deposit_amount',
        'interest_rate',
        'terms_conditions',
        'contract_file',
        'status',
        'last_notification_sent',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'last_notification_sent' => 'date',
        'monthly_rental' => 'decimal:2',
        'deposit_amount' => 'decimal:2',
        'interest_rate' => 'decimal:2',
    ];

    /**
     * Get the tenant that owns the contract.
     */
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get the rental space for the contract.
     */
    public function rentalSpace()
    {
        return $this->belongsTo(RentalSpace::class);
    }

    /**
     * Get the payments for the contract.
     */
    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Get the demand letters for the contract.
     */
    public function demandLetters()
    {
        return $this->hasMany(DemandLetter::class);
    }

    /**
     * Get pending payments.
     */
    public function pendingPayments()
    {
        return $this->hasMany(Payment::class)->whereIn('status', ['pending', 'overdue']);
    }

    /**
     * Get overdue payments.
     */
    public function overduePayments()
    {
        return $this->hasMany(Payment::class)->where('status', 'overdue');
    }

    /**
     * Check if contract is active.
     */
    public function isActive()
    {
        return $this->status === 'active';
    }

    /**
     * Check if contract is expired.
     */
    public function isExpired()
    {
        return $this->status === 'expired' || Carbon::now()->isAfter($this->end_date);
    }

    /**
     * Check if contract is for renewal (2 months before expiry).
     */
    public function isForRenewal()
    {
        return $this->status === 'for_renewal';
    }

    /**
     * Check if contract is expiring soon (within 30 days).
     */
    public function isExpiringSoon()
    {
        return $this->end_date->diffInDays(Carbon::now()) <= 30 && $this->end_date->isFuture();
    }

    /**
     * Check if contract needs renewal (2 months before expiry).
     */
    public function needsRenewal()
    {
        $twoMonthsFromNow = Carbon::now()->addMonths(2);
        return in_array($this->status, ['active', 'for_renewal']) && 
               $this->end_date->lte($twoMonthsFromNow) && 
               $this->end_date->gt(Carbon::now());
    }

    /**
     * Update contract statuses dynamically:
     * - Active contracts within 2 months of expiry -> for_renewal
     * - Contracts past end_date -> expired and release rental space
     */
    public static function updateStatuses()
    {
        $now = Carbon::now();
        $twoMonthsFromNow = $now->copy()->addMonths(2);

        // 1. Update active contracts with two months or less remaining to 'for_renewal'
        self::where('status', 'active')
            ->where('end_date', '<=', $twoMonthsFromNow)
            ->where('end_date', '>', $now)
            ->update(['status' => 'for_renewal']);

        // 2. A renewed contract stays renewed for the 5-day grace period after renewal,
        // then it becomes active again.
        $renewedContracts = self::where('status', 'renewed')
            ->where('start_date', '<=', $now->copy()->subDays(5))
            ->get();

        foreach ($renewedContracts as $contract) {
            $contract->status = 'active';
            $contract->save();
        }

        // 3. Update contracts past end_date to 'expired'
        $expiredContracts = self::whereIn('status', ['active', 'for_renewal'])
            ->where('end_date', '<', $now)
            ->whereNot('status', 'renewed')
            ->get();

        foreach ($expiredContracts as $contract) {
            $contract->status = 'expired';
            $contract->save();

            if ($contract->rentalSpace) {
                // Only free space if there is no other active/for_renewal contract
                $hasOtherActive = self::where('rental_space_id', $contract->rental_space_id)
                    ->where('id', '!=', $contract->id)
                    ->whereIn('status', ['active', 'for_renewal'])
                    ->exists();

                if (!$hasOtherActive) {
                    $contract->rentalSpace->update(['status' => 'available']);
                }
            }
        }

        RentalSpace::syncOccupancyStatuses();
    }

    /**
     * Create activation notification for admin/staff/cashier.
     * 
     * This creates an IN-APP NOTIFICATION for admin/staff/cashier users.
     * Sent when a contract is activated.
     * 
     * @return bool True if notification created successfully, false otherwise
     */
    public function createActivationNotification()
    {
        try {
            // Ensure relationships are loaded
            if (!$this->tenant) {
                $this->load('tenant');
            }

            if (!$this->rentalSpace) {
                $this->load('rentalSpace');
            }

            // Get all admin, staff, and cashier users
            $adminUsers = User::whereIn('role', ['admin', 'staff', 'cashier'])->get();

            if ($adminUsers->isEmpty()) {
                \Log::warning('Contract activation notification failed - no admin users found', [
                    'contract_id' => $this->id,
                    'contract_number' => $this->contract_number,
                ]);
                return false;
            }

            // Create notification for each admin user
            foreach ($adminUsers as $user) {
                $tenantName = $this->tenant->business_name ?? 'N/A';
                
                Notification::create([
                    'user_id' => $user->id,
                    'type' => 'contract_activated',
                    'title' => 'Contract Activated',
                    'message' => "Contract #{$this->contract_number} for {$this->rentalSpace->name} (Tenant: {$tenantName}) has been activated. Payment schedule is now active.",
                    'data' => [
                        'contract_id' => (int) $this->id,
                        'contract_number' => (string) $this->contract_number,
                        'rental_space' => (string) $this->rentalSpace->name,
                        'tenant_name' => (string) $tenantName,
                        'tenant_id' => (int) $this->tenant_id,
                    ],
                    'is_read' => false,
                    'email_sent' => false,
                ]);
            }

            \Log::info('Contract activation notification created for admin users', [
                'contract_id' => $this->id,
                'contract_number' => $this->contract_number,
                'tenant_id' => $this->tenant_id,
            ]);

            return true;
        } catch (\Exception $e) {
            \Log::error('Failed to create activation notification', [
                'contract_id' => $this->id,
                'contract_number' => $this->contract_number,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Create renewal notification for admin/staff/cashier.
     * 
     * This creates an IN-APP NOTIFICATION ONLY (no email is sent).
     * Notification is created for all admin, staff, and cashier users.
     * 
     * @return bool True if notification created successfully, false otherwise
     */
    public function createRenewalNotification()
    {
        try {
            $daysUntilExpiry = $this->daysUntilExpiration();
            
            // Only create notification if not sent recently (within 7 days)
            if ($this->last_notification_sent && $this->last_notification_sent->diffInDays(Carbon::now()) < 7) {
                return false;
            }

            // Ensure relationships are loaded
            if (!$this->tenant) {
                $this->load('tenant');
            }

            if (!$this->rentalSpace) {
                $this->load('rentalSpace');
            }

            // Get all admin, staff, and cashier users
            $adminUsers = User::whereIn('role', ['admin', 'staff', 'cashier'])->get();

            if ($adminUsers->isEmpty()) {
                \Log::warning('Contract renewal notification failed - no admin users found', [
                    'contract_id' => $this->id,
                    'contract_number' => $this->contract_number,
                ]);
                return false;
            }

            // Create notification for each admin user
            foreach ($adminUsers as $user) {
                $tenantName = $this->tenant->business_name ?? 'N/A';
                
                Notification::create([
                    'user_id' => $user->id,
                    'type' => 'contract_renewal',
                    'title' => 'Contract Renewal Notice',
                    'message' => "Contract #{$this->contract_number} for {$this->rentalSpace->name} (Tenant: {$tenantName}) will expire in {$daysUntilExpiry} days ({$this->end_date->format('M d, Y')}). Please contact tenant to discuss renewal options.",
                    'data' => [
                        'contract_id' => (int) $this->id,
                        'contract_number' => (string) $this->contract_number,
                        'rental_space' => (string) $this->rentalSpace->name,
                        'tenant_name' => (string) $tenantName,
                        'tenant_id' => (int) $this->tenant_id,
                        'expiry_date' => $this->end_date->format('Y-m-d'),
                        'days_until_expiry' => (int) $daysUntilExpiry,
                    ],
                    'is_read' => false,
                    'email_sent' => false, // In-app notification only, NOT sent via email
                ]);
            }

            // Update last notification sent timestamp
            $this->update(['last_notification_sent' => Carbon::now()]);
            
            \Log::info('Contract renewal notification created for admin users', [
                'contract_id' => $this->id,
                'contract_number' => $this->contract_number,
                'tenant_id' => $this->tenant_id,
            ]);

            return true;
        } catch (\Exception $e) {
            \Log::error('Failed to create renewal notification', [
                'contract_id' => $this->id,
                'contract_number' => $this->contract_number,
                'error' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'tenant_id' => $this->tenant_id,
                'has_tenant' => (bool) $this->tenant,
                'has_user' => $this->tenant ? (bool) $this->tenant->user : false,
            ]);
            return false;
        }
    }

    /**
     * Get days until expiration.
     */
    public function daysUntilExpiration()
    {
        return Carbon::now()->diffInDays($this->end_date, false);
    }

    /**
     * Calculate total amount paid.
     */
    public function totalPaid()
    {
        return $this->payments()->sum('amount_paid');
    }

    /**
     * Calculate total balance.
     */
    public function totalBalance()
    {
        return $this->payments()->sum('balance');
    }

    /**
     * Generate payment schedules for the contract.
     * Billing periods based on contract start date anniversary.
     */
    public function generatePaymentSchedule()
    {
        if ($this->payments()->exists()) {
            return;
        }

        $startDate = Carbon::parse($this->start_date);
        $endDate = Carbon::parse($this->end_date);
        $monthCount = 0;

        while ($monthCount < 60) { // Limit to 60 months
            $periodStart = $startDate->copy()->addMonths($monthCount);
            $periodEnd = $startDate->copy()->addMonths($monthCount + 1);

            if ($periodStart->gt($endDate)) {
                break;
            }

            if ($periodEnd->gt($endDate)) {
                $periodEnd = $endDate->copy();
            }

            $dueDate = $periodEnd->copy();

            $lastPayment = Payment::orderBy('id', 'desc')->first();
            $nextNumber = ($lastPayment ? intval(substr($lastPayment->payment_number, -6)) : 0) + 1;
            $paymentNumber = 'PAY-' . date('Y') . '-' . str_pad($nextNumber, 6, '0', STR_PAD_LEFT);

            Payment::create([
                'payment_number' => $paymentNumber,
                'contract_id' => $this->id,
                'tenant_id' => $this->tenant_id,
                'billing_period_start' => $periodStart,
                'billing_period_end' => $periodEnd,
                'due_date' => $dueDate,
                'amount_due' => $this->monthly_rental,
                'interest_amount' => $this->monthly_rental * 0.03,
                'total_amount' => $this->monthly_rental * 1.03,
                'amount_paid' => 0,
                'balance' => $this->monthly_rental * 1.03,
                'status' => 'pending',
            ]);

            $monthCount++;
        }
    }
}
