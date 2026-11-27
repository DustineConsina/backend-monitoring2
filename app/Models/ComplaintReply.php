<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ComplaintReply extends Model
{
    protected $fillable = [
        'complaint_id',
        'sender_id',
        'message',
        'request_key',
        'email_sent_at',
        'image_path',
    ];

    protected $hidden = ['image_path'];

    protected $appends = ['has_image'];

    protected $casts = [
        'email_sent_at' => 'datetime',
    ];

    public function getHasImageAttribute(): bool
    {
        return is_string($this->image_path) && $this->image_path !== '';
    }

    public function complaint()
    {
        return $this->belongsTo(Complaint::class);
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}
