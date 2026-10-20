<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Services\OverduePaymentSmsNotifier;
use Carbon\Carbon;
use Illuminate\Console\Command;

class UpdateOverdueStatus extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'payments:update-overdue-status';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update payment status to overdue if due date has passed';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Updating overdue payment statuses...');

        // Find all pending payments where due date + 5 days has passed
        $overduePayments = Payment::where('status', 'pending')
            ->where('balance', '>', 0)
            ->whereRaw('DATE_ADD(due_date, INTERVAL 5 DAY) <= CURDATE()')
            ->get();

        $count = 0;

        foreach ($overduePayments as $payment) {
            $payment->update([
                'status' => 'overdue',
            ]);
            app(OverduePaymentSmsNotifier::class)->sendIfDue($payment);
            
            // Log audit trail
            \Log::info("Payment {$payment->payment_number} marked as overdue (5 days after due date). Amount due (with 3% interest): {$payment->amount_due}. Balance: {$payment->balance}");
            $count++;
        }

        $this->info("Updated {$count} payment(s) to overdue status (5 days after due date).");
        return 0;
    }
}
