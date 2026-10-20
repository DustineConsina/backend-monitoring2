<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Payment;
use App\Models\Contract;
use App\Services\OverduePaymentSmsNotifier;
use Carbon\Carbon;

class CalculateOverdueInterest extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'payments:calculate-overdue-interest';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Calculate and apply interest to overdue payments';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Calculating overdue interest...');

        // Get all pending payments that are past due date
        $overduePayments = Payment::where('status', 'pending')
            ->where('due_date', '<', Carbon::now())
            ->get();

        $count = 0;
        foreach ($overduePayments as $payment) {
            $payment->calculateInterest();
            app(OverduePaymentSmsNotifier::class)->sendIfDue($payment);
            $count++;
        }

        // Also recalculate for already overdue payments (monthly interest)
        $existingOverdue = Payment::where('status', 'overdue')->get();
        foreach ($existingOverdue as $payment) {
            $payment->calculateInterest();
        }

        $this->info("Calculated interest for {$count} newly overdue payments");
        $this->info("Updated interest for " . $existingOverdue->count() . " existing overdue payments");
        $this->info('Overdue interest calculation completed!');

        return Command::SUCCESS;
    }
}
