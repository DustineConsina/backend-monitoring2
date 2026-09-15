<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_id',
        'amount',
        'paid_date',
        'payment_method',
        'payment_provider',
        'reference_number',
        'recorded_by',
        'remarks',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_date' => 'date',
    ];

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}