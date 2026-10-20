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

        try {
            $this->send($payment);
        } catch (\Throwable $error) {
            Log::warning('Failed to send overdue payment SMS.', [
                'payment_id' => $payment->id,
                'error' => $error->getMessage(),
            ]);
        }
    }

    public function retryConfigurationFailure(Payment $payment, int $senderId): string
    {
        if ($payment->status !== 'overdue' || (float) $payment->balance <= 0) {
            throw new \RuntimeException('Only overdue payments with an outstanding balance can be retried.');
        }

        $lastAttempt = SmsMessage::where('payment_id', $payment->id)->latest()->first();
        if (
            !$lastAttempt ||
            strtolower((string) $lastAttempt->status) !== 'failed' ||
            trim((string) $lastAttempt->error_message) !== 'SEMAPHORE_API_KEY is not configured.'
        ) {
            throw new \RuntimeException('This payment does not have a retryable missing-API-key SMS failure.');
        }

        return $this->send($payment, $senderId);
    }

    private function send(Payment $payment, ?int $senderId = null): string
    {
        $payment->loadMissing(['tenant.user']);
        $tenant = $payment->tenant;
        if (!$tenant) {
            throw new \RuntimeException('The overdue payment tenant record is missing.');
        }

        $phoneNumber = trim((string) ($tenant->contact_number ?: $tenant->user?->phone ?: ''));
        $tenantName = (string) ($tenant->contact_person ?: $tenant->user?->name ?: $tenant->business_name ?: 'Tenant');
        $billingMonth = $payment->billing_period_start?->format('F Y')
            ?? $payment->due_date?->format('F Y')
            ?? now()->format('F Y');
        $dueDate = $payment->due_date?->format('F j, Y') ?? 'the due date';

        return app(SemaphoreSmsService::class)->send(
            $phoneNumber,
            SmsMessageTemplates::overduePayment(
                $tenantName,
                (string) $payment->payment_number,
                (float) $payment->balance,
                $billingMonth,
                $dueDate
            ),
            $senderId,
            (int) $payment->id
        );
    }
}
