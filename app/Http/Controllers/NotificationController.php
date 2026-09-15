<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * Get all notifications for current user
     */
    public function index(Request $request)
    {
        $query = $request->user()->notifications();

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        if ($request->has('is_read')) {
            $query->where('is_read', $request->boolean('is_read'));
        }

        $notifications = $query
            ->orderByRaw('is_read ASC, created_at DESC')
            ->paginate($request->get('per_page', 20));

        $notifications->getCollection()->transform(function ($notification) {
            $notification->is_read = (bool) $notification->is_read;
            return $notification;
        });

        return response()->json([
            'success' => true,
            'data' => $notifications,
            'unread_count' => $request->user()->notifications()->unread()->count(),
        ]);
    }

    /**
     * Get unread notifications
     */
    public function getUnread(Request $request)
    {
        $notifications = $request->user()
            ->notifications()
            ->unread()
            ->orderByDesc('created_at')
            ->take(10)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $notifications,
            'unread_count' => $notifications->count(),
        ]);
    }

    /**
     * Get unread count
     */
    public function getUnreadCount(Request $request)
    {
        $count = $request->user()
            ->notifications()
            ->unread()
            ->count();

        return response()->json([
            'success' => true,
            'count' => $count
        ]);
    }

    /**
     * Mark notification as read
     */
    public function markAsRead($id)
    {
        $notification = Notification::findOrFail($id);

        if ($notification->user_id !== auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        if (!$notification->is_read) {
            $notification->markAsRead();
        }

        return response()->json([
            'success' => true,
            'message' => 'Notification marked as read',
            'unread_count' => auth()->user()->notifications()->unread()->count(),
            'data' => $notification->fresh(),
        ]);
    }

    /**
     * Mark all notifications as read
     */
    public function markAllAsRead(Request $request)
    {
        $updated = $request->user()
            ->notifications()
            ->unread()
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);

        return response()->json([
            'success' => true,
            'message' => 'All notifications marked as read',
            'updated_count' => $updated,
            'unread_count' => 0,
        ]);
    }

    /**
     * Delete a notification
     */
    public function stream(Request $request)
    {
        if (ob_get_level() === 0) {
            ob_start();
        }

        $request->setLaravelSession($request->session());

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        echo "retry: 2000\n\n";
        flush();

        $lastKnownCount = $request->user()->notifications()->unread()->count();
        $lastNotificationId = $request->user()->notifications()->max('id') ?? 0;

        while (true) {
            $currentCount = $request->user()->notifications()->unread()->count();
            $currentLastId = $request->user()->notifications()->max('id') ?? 0;

            if ($currentCount !== $lastKnownCount || $currentLastId !== $lastNotificationId) {
                echo "event: notification:update\n";
                echo 'data: ' . json_encode([
                    'unread_count' => $currentCount,
                    'last_notification_id' => $currentLastId,
                    'timestamp' => now()->toISOString(),
                ]) . "\n\n";
                flush();

                $lastKnownCount = $currentCount;
                $lastNotificationId = $currentLastId;
            }

            usleep(2000000);
        }
    }

    public function destroy($id)
    {
        $notification = Notification::findOrFail($id);

        if ($notification->user_id !== auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $notification->delete();

        return response()->json([
            'success' => true,
            'message' => 'Notification deleted successfully'
        ]);
    }

    /**
     * Delete all notifications
     */
    public function deleteAll(Request $request)
    {
        $request->user()->notifications()->delete();

        return response()->json([
            'success' => true,
            'message' => 'All notifications deleted successfully'
        ]);
    }
}
