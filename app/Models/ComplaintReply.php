<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ComplaintReply extends Model
{
    protected $fillable = [
        'complaint_id',
        'sender_id',
        'message',
    ];

    public function complaint()
    {
        return $this->belongsTo(Complaint::class);
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}
