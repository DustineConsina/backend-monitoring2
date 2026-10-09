<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class CreateCashierAccount extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cashier:create';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create cashier account if it does not exist';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $email = 'buizakeanalyn@pfda.gov.ph';
        
        $cashier = User::where('email', $email)->first();
        
        if ($cashier) {
            $this->info("✓ Cashier account already exists: {$email}");
            return 0;
        }
        
        try {
            User::create([
                'name' => 'Cashier User',
                'email' => $email,
                'password' => Hash::make('password123'),
                'role' => 'cashier',
                'phone' => '09123456791',
                'address' => 'PFDA Office, Bulan, Sorsogon',
                'status' => 'active',
            ]);
            
            $this->info("✓ Cashier account created successfully!");
            $this->line("  Email: {$email}");
            $this->line("  Password: password123");
            $this->line("  Role: cashier");
            
            return 0;
        } catch (\Exception $e) {
            $this->error("✗ Failed to create cashier account: " . $e->getMessage());
            return 1;
        }
    }
}
