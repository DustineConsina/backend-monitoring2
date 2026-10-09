<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmsMessage extends Model
{
    protected $fillable = [
        'sender_id',
        'recipient_user_id',
        'phone_number',
        'message',
        'provider',
        'provider_message_id',
        'status',
        'error_message',
        'payment_id',
        'contract_id',
    ];

    public function recipientUser()
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function contract()
    {
        return $this->belongsTo(Contract::class);
    }
}
