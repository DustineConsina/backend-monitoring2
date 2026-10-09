<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Create cashier account if it doesn't exist
        $cashierExists = User::where('email', 'cashier@pfda.gov.ph')->exists();
        
        if (!$cashierExists) {
            User::create([
                'name' => 'Keanalyn Buiza',
                'email' => 'cashier@pfda.gov.ph',
                'password' => Hash::make('password123'),
                'role' => 'cashier',
                'phone' => '09123456791',
                'address' => 'PFDA Office, Bulan, Sorsogon',
                'status' => 'active',
            ]);
            
            echo "✓ Cashier account created: cashier@pfda.gov.ph (password: password123)\n";
        } else {
            echo "ℹ Cashier account already exists\n";
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Don't delete on rollback - this is important account
    }
};
