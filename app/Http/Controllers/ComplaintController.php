<?php

namespace App\Http\Controllers;

use App\Models\Complaint;
use App\Models\ComplaintReply;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

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
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $complaint = $tenant->complaints()->create([
            'category' => $request->input('category'),
            'subject' => trim($request->input('subject')),
            'message' => trim($request->input('message')),
            'status' => 'pending',
            'admin_read' => false,
        ]);

        return response()->json(['success' => true, 'data' => $complaint], 201);
    }

    public function index()
    {
        return response()->json([
            'success' => true,
            'data' => Complaint::with(['tenant.user', 'replies'])->latest()->get(),
        ]);
    }

    public function unreadCount()
    {
        return response()->json([
            'success' => true,
            'count' => Complaint::where('admin_read', false)->count(),
        ]);
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

        $reply = $complaint->replies()->create([
            'sender_id' => $request->user()->id,
            'message' => trim($request->input('message')),
        ]);

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
            'reply' => ['nullable', 'string', 'max:5000', 'regex:/\S/'],
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $complaint = Complaint::findOrFail($id);
        $hasReply = $request->filled('reply');
        if ($hasReply && $complaint->status === 'resolved') {
            return response()->json([
                'success' => false,
                'message' => 'This request is resolved and no longer accepts replies.',
            ], 422);
        }

        $complaint->status = $request->input('status');
        if ($hasReply) {
            $message = trim($request->input('reply'));
            $complaint->replies()->create([
                'sender_id' => $request->user()->id,
                'message' => $message,
            ]);
            $complaint->reply = $message;
        }
        $complaint->save();

        return response()->json([
            'success' => true,
            'data' => $complaint->load(['tenant.user', 'replies']),
        ]);
    }
}
