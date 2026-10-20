<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\SmsMessage;
use Illuminate\Support\Facades\Log;

class OverduePaymentSmsNotifier
{
    public function sendIfDue(Payment $payment): void
    {
        if ($payment->status !== 'overdue' || (float) $payment->balance <= 0) {
            return;
        }

        $lastAttempt = SmsMessage::where('payment_id', $payment->id)->latest()->first();
        if ($lastAttempt?->created_at?->gt(now()->subDays(7))) {
            return;
        }

        $payment->loadMissing(['tenant.user']);
        $tenant = $payment->tenant;
        if (!$tenant) {
            Log::warning('Unable to send overdue payment SMS because the tenant record is missing.', [
                'payment_id' => $payment->id,
            ]);
            return;
        }

        $phoneNumber = trim((string) ($tenant->contact_number ?: $tenant->user?->phone ?: ''));
        $tenantName = (string) ($tenant->contact_person ?: $tenant->user?->name ?: $tenant->business_name ?: 'Tenant');
        $billingMonth = $payment->billing_period_start?->format('F Y')
            ?? $payment->due_date?->format('F Y')
            ?? now()->format('F Y');
        $dueDate = $payment->due_date?->format('F j, Y') ?? 'the due date';

        try {
            app(SemaphoreSmsService::class)->send(
                $phoneNumber,
                SmsMessageTemplates::overduePayment(
                    $tenantName,
                    (string) $payment->payment_number,
                    (float) $payment->balance,
                    $billingMonth,
                    $dueDate
                ),
                null,
                (int) $payment->id
            );
        } catch (\Throwable $error) {
            Log::warning('Failed to send overdue payment SMS.', [
                'payment_id' => $payment->id,
                'error' => $error->getMessage(),
            ]);
        }
    }
}
