<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RentalSpace extends Model
{
    use HasFactory;

    public static function occupiedContractStatuses(): array
    {
        return ['active', 'for_renewal', 'pending', 'renewed'];
    }

    public static function blockingContractStatuses(): array
    {
        return ['active', 'for_renewal', 'pending', 'renewed'];
    }

    protected $fillable = [
        'space_code',
        'space_type',
        'name',
        'size_sqm',
        'description',
        'map_image',
        'base_rental_rate',
        'status',
    ];

    protected $casts = [
        'size_sqm' => 'decimal:2',
        'base_rental_rate' => 'decimal:2',
    ];

    /**
     * Get the contracts for this rental space.
     */
    public function contracts()
    {
        return $this->hasMany(Contract::class);
    }

    /**
     * Get the active contract for this rental space.
     */
    public function activeContract()
    {
        return $this->hasOne(Contract::class)->whereIn('status', self::occupiedContractStatuses());
    }

    /**
     * Get the current tenant.
     */
    public function currentTenant()
    {
        return $this->hasOneThrough(
            Tenant::class,
            Contract::class,
            'rental_space_id',
            'id',
            'id',
            'tenant_id'
        )->whereIn('contracts.status', self::occupiedContractStatuses());
    }

    /**
     * Check if space is available.
     */
    public function isAvailable()
    {
        $hasBlockingContract = $this->contracts()->whereIn('status', self::blockingContractStatuses())->exists();

        if ($hasBlockingContract) {
            return false;
        }

        $status = strtolower((string) ($this->status ?? ''));

        return $status === 'available' || $status === '' || is_null($this->status);
    }

    /**
     * Scope: Only available rental spaces (without active, renewal, or pending contracts).
     */
    public function scopeAvailable($query)
    {
        return $query->whereDoesntHave('contracts', function ($q) {
            $q->whereIn('status', self::occupiedContractStatuses());
        })->where(function ($q) {
            $q->whereIn('status', ['available', ''])
              ->orWhereNull('status');
        });
    }

    /**
     * Scope: Only occupied rental spaces (with active contracts)
     */
    public function scopeOccupied($query)
    {
        return $query->whereHas('contracts', function ($q) {
            $q->whereIn('status', self::occupiedContractStatuses());
        });
    }

    /**
     * Reconcile the stored status with current active contract assignments.
     */
    public static function syncOccupancyStatuses()
    {
        $occupiedContractStatuses = self::occupiedContractStatuses();

        self::whereHas('contracts', function ($query) use ($occupiedContractStatuses) {
            $query->whereIn('status', $occupiedContractStatuses);
        })->whereNotIn('status', ['occupied', 'maintenance', 'reserved', 'rented', 'terminated', 'unavailable'])
          ->update(['status' => 'occupied']);

        self::where(function ($query) {
            $query->whereIn('status', ['occupied', 'reserved', 'rented', 'unavailable', 'terminated'])
                ->orWhere('status', 'maintenance');
        })->whereDoesntHave('contracts', function ($query) use ($occupiedContractStatuses) {
            $query->whereIn('status', $occupiedContractStatuses);
        })->update(['status' => 'available']);
    }

    /**
     * Get the space type label.
     */
    public function getSpaceTypeLabel()
    {
        return match($this->space_type) {
            'food_stall' => 'Food Stall',
            'market_hall' => 'Market Hall',
            'banera_warehouse' => 'Bañera Warehouse',
            default => $this->space_type,
        };
    }
}
