<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Update contract statuses daily at 6 AM
        $schedule->command('contracts:update-expired')
                 ->dailyAt('06:00')
                 ->description('Update contract statuses (mark as for_renewal or expired)');

        // Update payment statuses to overdue daily at 7 AM
        $schedule->command('payments:update-overdue-status')
                 ->dailyAt('07:00')
                 ->description('Update overdue payment statuses');

        // Send overdue SMS reminders after payment statuses are updated.
        $schedule->command('payments:send-reminders')
             ->dailyAt('08:00')
             ->description('Send payment reminders, including Semaphore SMS');

        // Run demand letter generation daily at 8 AM
        $schedule->command('payments:generate-demand-letters')
                 ->dailyAt('08:00')
                 ->description('Generate and send demand letters for overdue payments');

        // Send contract renewal notifications daily at 9 AM
        $schedule->command('contracts:send-renewal-notifications')
                 ->dailyAt('09:00')
                 ->description('Send renewal notifications for contracts expiring soon');

        $schedule->command('sms:refresh-statuses')
                 ->everyFifteenMinutes()
                 ->withoutOverlapping()
                 ->description('Update pending SMS delivery statuses from Semaphore');
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
