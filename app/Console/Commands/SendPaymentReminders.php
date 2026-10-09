<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Payment;
use App\Models\Notification;
use App\Mail\PaymentReminderMail;
use App\Models\SmsMessage;
use App\Services\SemaphoreSmsService;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;

class SendPaymentReminders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'payments:send-reminders';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send payment reminders to tenants for upcoming and overdue payments';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Sending payment reminders...');

        // Get payments due in the next 7 days
        $upcomingPayments = Payment::with(['tenant.user', 'contract.rentalSpace'])
            ->where('status', 'pending')
            ->whereBetween('due_date', [Carbon::now(), Carbon::now()->addDays(7)])
            ->get();

        $upcomingCount = 0;
        foreach ($upcomingPayments as $payment) {
            $daysUntilDue = Carbon::now()->diffInDays($payment->due_date, false);
            
            // Create notification
            $notification = Notification::create([
                'user_id' => $payment->tenant->user_id,
                'type' => 'payment_due',
                'title' => 'Payment Due Soon',
                'message' => "Payment {$payment->payment_number} for {$payment->contract->rentalSpace->name} is due in {$daysUntilDue} days. Amount: ₱" . number_format($payment->total_amount, 2),
                'data' => ['payment_id' => $payment->id, 'days_until_due' => $daysUntilDue],
            ]);

            // Send email
            try {
                if ($payment->tenant->user->email) {
                    Mail::to($payment->tenant->user->email)->send(new PaymentReminderMail($payment, 'upcoming'));
                    $notification->markEmailAsSent();
                    $upcomingCount++;
                }
            } catch (\Exception $e) {
                $this->error("Failed to send email to {$payment->tenant->user->email}: " . $e->getMessage());
            }
        }

        // Get overdue payments
        $overduePayments = Payment::with(['tenant.user', 'contract.rentalSpace'])
            ->where('status', 'overdue')
            ->where('balance', '>', 0)
            ->get();

        $overdueCount = 0;
        foreach ($overduePayments as $payment) {
            $daysOverdue = $payment->daysOverdue();
            
            // Notifications and SMS have separate cooldowns; only actual SMS attempts suppress a retry.
            $lastNotification = Notification::where('user_id', $payment->tenant->user_id)
                ->where('type', 'payment_overdue')
                ->where('data->payment_id', $payment->id)
                ->latest()
                ->first();

            $notificationDue = !$lastNotification || $lastNotification->created_at->diffInDays(Carbon::now()) >= 7;
            if ($notificationDue) {
                $notification = Notification::create([
                    'user_id' => $payment->tenant->user_id,
                    'type' => 'payment_overdue',
                    'title' => 'Payment Overdue',
                    'message' => "Payment {$payment->payment_number} is {$daysOverdue} days overdue. Total amount (with interest): ₱" . number_format($payment->total_amount, 2),
                    'data' => ['payment_id' => $payment->id, 'days_overdue' => $daysOverdue],
                ]);

                // Send email
                try {
                    if ($payment->tenant->user->email) {
                        Mail::to($payment->tenant->user->email)->send(new PaymentReminderMail($payment, 'overdue'));
                        $notification->markEmailAsSent();
                        $overdueCount++;
                    }
                } catch (\Exception $e) {
                    $this->error("Failed to send email to {$payment->tenant->user->email}: " . $e->getMessage());
                }

            }

            $lastSmsAttempt = SmsMessage::where('payment_id', $payment->id)->latest()->first();
            $smsAttemptDue = !$lastSmsAttempt || $lastSmsAttempt->created_at->diffInDays(Carbon::now()) >= 7;
            if ($smsAttemptDue) {
                $phoneNumber = trim((string) ($payment->tenant->contact_number ?: $payment->tenant->user?->phone ?: ''));
                try {
                    $messageId = app(SemaphoreSmsService::class)->send(
                        $phoneNumber,
                        \App\Services\SmsMessageTemplates::overduePayment(
                            (string) ($payment->tenant->contact_person ?: $payment->tenant->user?->name ?: $payment->tenant->business_name),
                            (string) $payment->payment_number,
                            (float) $payment->balance,
                            $payment->billing_period_start->format('F Y'),
                            $payment->due_date->format('F j, Y')
                        ),
                        null,
                        (int) $payment->id
                    );
                    $this->line("  SMS accepted for payment {$payment->payment_number} (message {$messageId})");
                } catch (\Throwable $e) {
                    $this->error("Failed to send SMS for payment {$payment->payment_number}: " . $e->getMessage());
                }
            }
        }

        $this->info("Sent {$upcomingCount} upcoming payment reminders");
        $this->info("Sent {$overdueCount} overdue payment reminders");
        $this->info('Payment reminders completed!');

        return Command::SUCCESS;
    }
}
