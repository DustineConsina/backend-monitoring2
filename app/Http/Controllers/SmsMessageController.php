<?php

namespace App\Http\Controllers;

use App\Models\SmsMessage;
use App\Models\Tenant;
use App\Services\SemaphoreSmsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SmsMessageController extends Controller
{
    public function index()
    {
        return response()->json([
            'success' => true,
            'data' => SmsMessage::with('recipientUser.tenant', 'payment', 'contract')
                ->latest('created_at')
                ->latest('id')
                ->get(),
        ]);
    }

    public function store(Request $request, SemaphoreSmsService $smsService)
    {
        $validator = Validator::make($request->all(), [
            'phone_number' => ['required', 'string', 'max:32'],
            'message' => ['required', 'string', 'max:918'],
            'recipient_user_id' => ['nullable', 'exists:users,id'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $phoneNumber = trim($request->string('phone_number')->toString());
        $message = trim($request->string('message')->toString());
        $recipientUserId = $request->input('recipient_user_id');

        if (!$recipientUserId) {
            $tenant = Tenant::with('user')
                ->where('contact_number', $phoneNumber)
                ->orWhereHas('user', fn ($query) => $query->where('phone', $phoneNumber))
                ->first();
            $recipientUserId = $tenant?->user_id;
        }

        $providerMessageId = $smsService->send($phoneNumber, $message, (int) $request->user()->id);
        $smsMessage = SmsMessage::where('provider_message_id', $providerMessageId)->firstOrFail();

        if ($recipientUserId && !$smsMessage->recipient_user_id) {
            $smsMessage->recipient_user_id = $recipientUserId;
            $smsMessage->save();
        }

        return response()->json([
            'success' => true,
            'data' => $smsMessage->load('recipientUser.tenant'),
        ], 201);
    }
}
