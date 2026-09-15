<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    use HasFactory;

    protected $hidden = [
        'qr_password_hash',
    ];

    protected $fillable = [
        'user_id',
        'tenant_code',
        'qr_password_hash',
        'qr_access_code',
        'business_name',
        'business_type',
        'business_permit_number',
        'business_address',
        'contact_person',
        'contact_number',
        'qr_code',
        'profile_picture',
        'status',
    ];

    protected $casts = [
        'qr_access_code' => 'encrypted',
    ];

    /**
     * Get the user that owns the tenant.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the contracts for the tenant.
     */
    public function contracts()
    {
        return $this->hasMany(Contract::class);
    }

    /**
     * Get the active contracts for the tenant.
     */
    public function activeContracts()
    {
        return $this->hasMany(Contract::class)->where('status', 'active');
    }

    /**
     * Get the payments for the tenant.
     */
    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Get the overdue payments for the tenant.
     */
    public function overduePayments()
    {
        return $this->hasMany(Payment::class)->where('status', 'overdue');
    }

    /**
     * Check if tenant has active contracts.
     */
    public function hasActiveContract()
    {
        return $this->activeContracts()->exists();
    }

    /**
     * Check if tenant is active.
     */
    public function isActive()
    {
        return $this->status === 'active';
    }
}
