<?php

namespace App\Http\Controllers;

use App\Models\Complaint;
use App\Models\ComplaintReply;
use App\Models\Tenant;
use App\Mail\ComplaintResponseNotification;
use App\Services\TenantQrToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Database\QueryException;
use Throwable;

class ComplaintController extends Controller
{
    public function mine(Request $request)
    {
        $tenant = $request->user()->tenant;
        if (!$tenant || $tenant->status !== 'active') {
            return response()->json(['success' => false, 'message' => 'Tenant account not found or inactive.'], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $tenant->complaints()->with('replies')->latest()->get(),
        ]);
    }

    public function store(Request $request)
    {
        $tenant = $request->user()->tenant;
        if (!$tenant || $tenant->status !== 'active') {
            return response()->json(['success' => false, 'message' => 'Tenant account not found or inactive.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'category' => ['required', 'in:rental_space,payments,contracts,facilities,staff_service,other'],
            'subject' => ['required', 'string', 'min:3', 'max:150', 'regex:/\S/'],
            'message' => ['required', 'string', 'min:10', 'max:5000', 'regex:/\S/'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $complaint = $this->createComplaint($tenant, $request);

        return response()->json(['success' => true, 'data' => $complaint], 201);
    }

    public function storeFromQr(Request $request, TenantQrToken $qrTokens)
    {
        $validator = Validator::make($request->all(), [
            'token' => ['required', 'string', 'max:2048'],
            'access_code' => ['required', 'digits:8'],
            'category' => ['required', 'in:rental_space,payments,contracts,facilities,staff_service,other'],
            'subject' => ['required', 'string', 'min:3', 'max:150', 'regex:/\S/'],
            'message' => ['required', 'string', 'min:10', 'max:5000', 'regex:/\S/'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $tenant = $qrTokens->resolve((string) $request->input('token'));
        if (!$tenant) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or unrecognized tenant QR code.',
            ], 404);
        }

        if (!$tenant->qr_password_hash || !Hash::check((string) $request->input('access_code'), $tenant->qr_password_hash)) {
            return response()->json([
                'success' => false,
                'message' => 'The QR password is incorrect.',
            ], 403);
        }

        $complaint = $this->createComplaint($tenant, $request);

        return response()->json([
            'success' => true,
            'message' => 'Your message has been submitted successfully.',
            'data' => $complaint,
        ], 201);
    }

    public function index()
    {
        return response()->json([
            'success' => true,
            'data' => Complaint::with(['tenant.user', 'replies'])->latest()->get(),
        ]);
    }

    public function recent()
    {
        return response()->json([
            'success' => true,
            'data' => Complaint::with([
                'tenant.user',
                'tenant.contracts' => function ($query) {
                    $query->with('rentalSpace')->latest();
                },
            ])
                ->orderByRaw('CASE WHEN admin_read = 0 THEN 0 ELSE 1 END')
                ->latest()
                ->limit(5)
                ->get(),
        ]);
    }

    public function unreadCount()
    {
        return response()->json([
            'success' => true,
            'count' => Complaint::where('admin_read', false)->count(),
        ]);
    }

    public function image($id)
    {
        $complaint = Complaint::findOrFail($id);
        return $this->privateImageResponse($complaint->image_path);
    }

    public function replyImage($id, $replyId)
    {
        $complaint = Complaint::findOrFail($id);
        $reply = $complaint->replies()->findOrFail($replyId);
        return $this->privateImageResponse($reply->image_path);
    }

    public function publicImage(Request $request, TenantQrToken $qrTokens)
    {
        $validator = Validator::make($request->all(), [
            'token' => ['required', 'string', 'max:2048'],
            'access_code' => ['required', 'digits:8'],
            'complaint_id' => ['required', 'integer'],
            'reply_id' => ['nullable', 'integer'],
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $tenant = $qrTokens->resolve((string) $request->input('token'));
        if (!$tenant || !$tenant->qr_password_hash || !Hash::check((string) $request->input('access_code'), $tenant->qr_password_hash)) {
            return response()->json(['success' => false, 'message' => 'Invalid QR access credentials.'], 403);
        }

        $complaint = $tenant->complaints()->find($request->integer('complaint_id'));
        if (!$complaint) {
            return response()->json(['success' => false, 'message' => 'Complaint not found.'], 404);
        }

        $imagePath = $request->filled('reply_id')
            ? $complaint->replies()->find($request->integer('reply_id'))?->image_path
            : $complaint->image_path;

        return $this->privateImageResponse($imagePath);
    }

    public function markRead($id)
    {
        $complaint = Complaint::findOrFail($id);
        if (!$complaint->admin_read) {
            $complaint->forceFill([
                'admin_read' => true,
                'admin_read_at' => now(),
            ])->save();
        }

        return response()->json(['success' => true]);
    }

    public function replyAsTenant(Request $request, $id)
    {
        $tenant = $request->user()->tenant;
        if (!$tenant || $tenant->status !== 'active') {
            return response()->json(['success' => false, 'message' => 'Tenant account not found or inactive.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'message' => ['required', 'string', 'min:2', 'max:5000', 'regex:/\S/'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $complaint = $tenant->complaints()->findOrFail($id);
        if (!in_array($complaint->status, ['pending', 'in_progress'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'You can only reply while this request is pending or in progress.',
            ], 422);
        }

        $imagePath = $request->file('image')?->store('complaints', 'local');
        try {
            $reply = $complaint->replies()->create([
                'sender_id' => $request->user()->id,
                'message' => trim($request->input('message')),
                'image_path' => $imagePath,
            ]);
        } catch (Throwable $exception) {
            if ($imagePath) {
                Storage::disk('local')->delete($imagePath);
            }
            throw $exception;
        }

        $complaint->forceFill(['admin_read' => false, 'admin_read_at' => null])->save();

        return response()->json([
            'success' => true,
            'data' => $reply->load('sender'),
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'status' => ['required', 'in:pending,in_progress,resolved'],
            'reply' => ['nullable', 'string', 'min:1', 'max:5000', 'regex:/\S/'],
            'request_key' => ['nullable', 'uuid'],
            'reply_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        if ($request->exists('request_key') && !$request->filled('reply')) {
            return response()->json([
                'success' => false,
                'message' => 'Enter a response before sending it.',
                'errors' => ['reply' => ['The response cannot be empty.']],
            ], 422);
        }
        if ($request->hasFile('reply_image') && !$request->filled('reply')) {
            return response()->json([
                'success' => false,
                'message' => 'Write a response before attaching an image.',
                'errors' => ['reply' => ['A response is required when attaching an image.']],
            ], 422);
        }

        $complaint = Complaint::findOrFail($id);
        $hasReply = $request->filled('reply');
        if ($hasReply && !$request->filled('request_key')) {
            return response()->json([
                'success' => false,
                'message' => 'A response request key is required.',
            ], 422);
        }

        if ($hasReply && $complaint->status === 'resolved') {
            return response()->json([
                'success' => false,
                'message' => 'This request is resolved and no longer accepts replies.',
            ], 422);
        }

        $notificationSent = null;
        $notificationMessage = null;
        if ($hasReply) {
            $message = trim($request->input('reply'));
            $requestKey = (string) $request->input('request_key');
            $imagePath = $request->file('reply_image')?->store('complaints', 'local');

            try {
                $reply = DB::transaction(function () use ($complaint, $request, $message, $requestKey, $imagePath) {
                    $existingReply = ComplaintReply::where('request_key', $requestKey)->lockForUpdate()->first();
                    if ($existingReply) {
                        return $existingReply;
                    }

                    $lockedComplaint = Complaint::lockForUpdate()->findOrFail($complaint->id);
                    if ($lockedComplaint->status === 'resolved') {
                        abort(422, 'This request is resolved and no longer accepts replies.');
                    }

                    $lockedComplaint->status = $request->input('status');
                    $lockedComplaint->reply = $message;
                    $lockedComplaint->save();

                    return $lockedComplaint->replies()->create([
                        'sender_id' => $request->user()->id,
                        'message' => $message,
                        'request_key' => $requestKey,
                        'image_path' => $imagePath,
                    ]);
                });
            } catch (QueryException $exception) {
                $reply = ComplaintReply::where('request_key', $requestKey)->first();
                if (!$reply) {
                    if ($imagePath) {
                        Storage::disk('local')->delete($imagePath);
                    }
                    throw $exception;
                }
            } catch (Throwable $exception) {
                if ($imagePath) {
                    Storage::disk('local')->delete($imagePath);
                }
                throw $exception;
            }

            if ($imagePath && $reply->image_path !== $imagePath) {
                Storage::disk('local')->delete($imagePath);
            }

            if ((int) $reply->complaint_id !== (int) $complaint->id || $reply->message !== $message) {
                return response()->json([
                    'success' => false,
                    'message' => 'This response request key has already been used.',
                ], 409);
            }

            [$notificationSent, $notificationMessage] = $this->sendResponseNotification($reply);
        } else {
            $complaint->status = $request->input('status');
            $complaint->save();
        }

        return response()->json([
            'success' => true,
            'data' => $complaint->fresh()->load(['tenant.user', 'replies']),
            'notification_sent' => $notificationSent,
            'notification_message' => $notificationMessage,
        ]);
    }

    private function createComplaint(Tenant $tenant, Request $request): Complaint
    {
        $imagePath = $request->file('image')?->store('complaints', 'local');

        try {
            return $tenant->complaints()->create([
                'category' => $request->input('category'),
                'subject' => trim($request->input('subject')),
                'message' => trim($request->input('message')),
                'status' => 'pending',
                'admin_read' => false,
                'image_path' => $imagePath,
            ]);
        } catch (Throwable $exception) {
            if ($imagePath) {
                Storage::disk('local')->delete($imagePath);
            }
            throw $exception;
        }
    }

    private function privateImageResponse(?string $imagePath)
    {
        if (!$imagePath || !Storage::disk('local')->exists($imagePath)) {
            return response()->json(['success' => false, 'message' => 'Image attachment not found.'], 404);
        }

        return response()->file(Storage::disk('local')->path($imagePath), [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function sendResponseNotification(ComplaintReply $reply): array
    {
        $reply->loadMissing('complaint.tenant.user');
        $tenant = $reply->complaint?->tenant;
        $email = $tenant?->user?->email;

        if (!is_string($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [false, 'Response saved, but notification could not be sent because the tenant has no valid registered email address.'];
        }

        $frontendUrl = rtrim((string) config('app.frontend_url'), '/');
        if ($frontendUrl === '') {
            return [false, 'Response saved, but the tenant portal link is not configured.'];
        }

        try {
            $sent = DB::transaction(function () use ($reply, $tenant, $email, $frontendUrl) {
                $lockedReply = ComplaintReply::lockForUpdate()->findOrFail($reply->id);
                if ($lockedReply->email_sent_at) {
                    return true;
                }

                Mail::to($email)->send(new ComplaintResponseNotification(
                    (string) ($tenant->contact_person ?: $tenant->business_name ?: 'Tenant'),
                    $frontendUrl . '/tenant-access',
                ));

                $lockedReply->forceFill(['email_sent_at' => now()])->save();
                return true;
            });

            return [$sent, $sent ? 'Response saved and notification email sent.' : 'Response saved, but the notification email was not sent.'];
        } catch (Throwable $exception) {
            Log::error('Complaint response email delivery failed.', [
                'complaint_id' => $reply->complaint_id,
                'reply_id' => $reply->id,
                'exception' => get_class($exception),
            ]);

            return [false, 'Response saved, but the notification email could not be sent. Check the email configuration and try again.'];
        }
    }
}
