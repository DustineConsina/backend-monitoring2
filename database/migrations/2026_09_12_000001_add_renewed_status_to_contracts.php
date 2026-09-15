<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        DB::statement("ALTER TABLE contracts MODIFY status ENUM('active', 'expired', 'terminated', 'pending', 'for_renewal', 'renewed') DEFAULT 'pending'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        DB::statement("ALTER TABLE contracts MODIFY status ENUM('active', 'expired', 'terminated', 'pending', 'for_renewal') DEFAULT 'pending'");
    }
};
