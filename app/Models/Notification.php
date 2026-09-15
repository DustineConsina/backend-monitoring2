<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class Notification extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'type',
        'title',
        'message',
        'data',
        'is_read',
        'email_sent',
        'read_at',
        'email_sent_at',
        'notification_key',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $notification) {
            if (!Schema::hasColumn('notifications', 'notification_key')) {
                return;
            }

            if (empty($notification->notification_key)) {
                $payload = [
                    'user_id' => $notification->user_id,
                    'type' => $notification->type,
                    'title' => $notification->title,
                    'message' => $notification->message,
                    'data' => $notification->data ?? [],
                ];

                try {
                    $messagePayload = json_encode($payload, JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    $messagePayload = json_encode($payload);
                }

                $notification->notification_key = md5((string) $messagePayload);
            }
        });
    }

    protected $casts = [
        'data' => 'array',
        'is_read' => 'boolean',
        'email_sent' => 'boolean',
        'read_at' => 'datetime',
        'email_sent_at' => 'datetime',
    ];

    /**
     * Get the user that owns the notification.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Mark notification as read.
     */
    public function markAsRead()
    {
        $this->is_read = true;
        $this->read_at = now();
        $this->save();
    }

    /**
     * Mark notification email as sent.
     */
    public function markEmailAsSent()
    {
        $this->email_sent = true;
        $this->email_sent_at = now();
        $this->save();
    }

    /**
     * Scope a query to only include unread notifications.
     */
    public function scopeUnread($query)
    {
        return $query->where('is_read', false);
    }

    /**
     * Scope a query to only include notifications with unsent emails.
     */
    public function scopeEmailNotSent($query)
    {
        return $query->where('email_sent', false);
    }
}
