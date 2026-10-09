<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Contract;
use Carbon\Carbon;

class UpdateExpiredContracts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'contracts:update-expired';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update status of contracts - mark as for_renewal (2 months before expiry) or expired';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Updating contract statuses...');

        // 1. Get active contracts that are 2 months before expiry and mark as "for_renewal"
        $twoMonthsFromNow = Carbon::now()->addMonths(2);
        $forRenewalContracts = Contract::where('status', 'active')
            ->where('end_date', '<=', $twoMonthsFromNow)
            ->where('end_date', '>', Carbon::now())
            ->get();

        $renewalCount = 0;
        foreach ($forRenewalContracts as $contract) {
            if ($contract->status !== 'for_renewal') {
                $contract->status = 'for_renewal';
                $contract->save();
                $this->info("Contract {$contract->contract_number} marked as for_renewal (expires in {$contract->daysUntilExpiration()} days)");
                $renewalCount++;
            }
        }

        // 2. Get contracts that have passed their end date and mark as "expired"
        $expiredContracts = Contract::where('status', 'active')
            ->where('end_date', '<', Carbon::now())
            ->get();

        $expiredCount = 0;
        foreach ($expiredContracts as $contract) {
            $contract->status = 'expired';
            $contract->save();

            // Update rental space status to available
            $rentalSpace = $contract->rentalSpace;
            if ($rentalSpace && strtolower((string) $rentalSpace->status) !== 'inactive') {
                $rentalSpace->status = 'available';
                $rentalSpace->save();
            }

            $this->info("Contract {$contract->contract_number} marked as expired");
            $expiredCount++;
        }

        // 3. Also check "for_renewal" contracts that have now passed expiry and update to expired
        $forRenewalExpired = Contract::where('status', 'for_renewal')
            ->where('end_date', '<', Carbon::now())
            ->get();

        foreach ($forRenewalExpired as $contract) {
            $contract->status = 'expired';
            $contract->save();

            // Update rental space status to available
            $rentalSpace = $contract->rentalSpace;
            if ($rentalSpace && strtolower((string) $rentalSpace->status) !== 'inactive') {
                $rentalSpace->status = 'available';
                $rentalSpace->save();
            }

            $this->info("Renewal contract {$contract->contract_number} marked as expired");
            $expiredCount++;
        }

        $this->info("Updated {$renewalCount} contract(s) to 'for_renewal' status");
        $this->info("Updated {$expiredCount} contract(s) to 'expired' status");
        $this->info('Contract status update completed!');

        return Command::SUCCESS;
    }
}
