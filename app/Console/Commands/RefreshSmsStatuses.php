<?php

namespace App\Console\Commands;

use App\Models\SmsMessage;
use App\Services\SemaphoreSmsService;
use Illuminate\Console\Command;

class RefreshSmsStatuses extends Command
{
    protected $signature = 'sms:refresh-statuses';

    protected $description = 'Refresh pending SMS delivery statuses from Semaphore';

    public function handle(SemaphoreSmsService $smsService): int
    {
        $refreshed = 0;
        $failedLookups = 0;

        SmsMessage::query()
            ->whereNotNull('provider_message_id')
            ->whereIn('status', ['queued', 'Queued', 'pending', 'Pending'])
            ->orderBy('id')
            ->chunkById(100, function ($messages) use ($smsService, &$refreshed, &$failedLookups) {
                foreach ($messages as $message) {
                    try {
                        $smsService->refreshStatus($message);
                        $refreshed++;
                    } catch (\Throwable $error) {
                        $failedLookups++;
                        $this->error("Could not refresh SMS {$message->provider_message_id}: {$error->getMessage()}");
                    }
                }
            });

        $this->info("Refreshed {$refreshed} SMS status record(s).");
        if ($failedLookups > 0) {
            $this->warn("Could not refresh {$failedLookups} SMS status record(s).");
        }

        return $failedLookups > 0 ? self::FAILURE : self::SUCCESS;
    }
}
