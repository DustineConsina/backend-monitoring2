<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Complaint extends Model
{
    protected $fillable = [
        'tenant_id',
        'category',
        'subject',
        'message',
        'status',
        'admin_read',
        'admin_read_at',
        'reply',
    ];

    protected $casts = [
        'admin_read' => 'boolean',
        'admin_read_at' => 'datetime',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function replies()
    {
        return $this->hasMany(ComplaintReply::class)->with('sender')->orderBy('created_at');
    }
}
