<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('tenants', 'qr_password_hash')) {
            Schema::table('tenants', function (Blueprint $table) {
                $table->string('qr_password_hash')->nullable()->after('tenant_code');
            });
        }

        DB::table('tenants')
            ->whereNull('qr_password_hash')
            ->orderBy('id')
            ->get(['id'])
            ->each(function (object $tenant): void {
                DB::table('tenants')
                    ->where('id', $tenant->id)
                    ->update(['qr_password_hash' => Hash::make((string) random_int(10000000, 99999999))]);
            });
    }

    public function down(): void
    {
        if (Schema::hasColumn('tenants', 'qr_password_hash')) {
            Schema::table('tenants', function (Blueprint $table) {
                $table->dropColumn('qr_password_hash');
            });
        }
    }
};
