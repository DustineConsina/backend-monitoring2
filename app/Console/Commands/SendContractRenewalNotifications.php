<?php

namespace App\Console\Commands;

use App\Models\Contract;
use App\Models\SmsMessage;
use App\Services\SemaphoreSmsService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SendContractRenewalNotifications extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'contracts:send-renewal-notifications {--days=60 : Check for contracts expiring within this many days}';

    /**
     * The description of the console command.
     *
     * @var string
     */
    protected $description = 'Send renewal notifications for contracts expiring soon';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $days = (int) $this->option('days');
        $this->info("Checking for contracts expiring within {$days} days...");

        try {
            // Include contracts already marked for renewal so their tenants receive the SMS.
            $expiryDate = Carbon::now()->addDays($days);
            
            $contracts = Contract::whereIn('status', ['active', 'for_renewal'])
                ->whereBetween('end_date', [Carbon::now(), $expiryDate])
                ->with(['tenant', 'tenant.user', 'rentalSpace'])
                ->get();

            $this->info("Found {$contracts->count()} contracts for renewal.");

            $notificationsSent = 0;
            $notificationsFailed = 0;

            foreach ($contracts as $contract) {
                $this->line("Processing: {$contract->contract_number}");

                try {
                    if ($contract->createRenewalNotification()) {
                        $notificationsSent++;
                        $this->line("  ✓ Notification sent for contract #{$contract->contract_number}");
                    } else {
                        $this->line("  Admin notification was sent recently for contract #{$contract->contract_number}");
                    }

                    $lastSmsAttempt = SmsMessage::where('contract_id', $contract->id)->latest()->first();
                    $smsAttemptDue = !$lastSmsAttempt || $lastSmsAttempt->created_at->diffInDays(Carbon::now()) >= 7;
                    if (!$smsAttemptDue) {
                        $this->line("  SMS attempt is within the 7-day cooldown for contract #{$contract->contract_number}");
                        continue;
                    }

                    $phoneNumber = trim((string) ($contract->tenant->contact_number ?: $contract->tenant->user?->phone ?: ''));
                    try {
                        $daysUntilExpiry = $contract->daysUntilExpiration();
                        $messageId = app(SemaphoreSmsService::class)->send(
                            $phoneNumber,
                            \App\Services\SmsMessageTemplates::contractRenewal(
                                (string) ($contract->tenant->contact_person ?: $contract->tenant->user?->name ?: $contract->tenant->business_name),
                                (string) ($contract->rentalSpace->space_code ?: $contract->rentalSpace->name ?: $contract->contract_number),
                                $contract->end_date->format('F j, Y')
                            ),
                            null,
                            null,
                            (int) $contract->id
                        );
                        $this->line("  SMS accepted for contract {$contract->contract_number} (message {$messageId})");
                    } catch (\Throwable $e) {
                        $this->error("  ✗ Failed to send renewal SMS: {$e->getMessage()}");
                    }
                } catch (\Exception $e) {
                    $notificationsFailed++;
                    $this->error("  ✗ Exception for contract #{$contract->contract_number}: {$e->getMessage()}");
                    $this->error("     File: {$e->getFile()}:{$e->getLine()}");
                }
            }

            $this->newLine();
            $this->info("Summary:");
            $this->info("  Notifications sent: {$notificationsSent}");
            $this->warn("  Notifications failed: {$notificationsFailed}");
            $this->info("Contract renewal notification check completed successfully!");

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Error: {$e->getMessage()}");
            return self::FAILURE;
        }
    }
}
