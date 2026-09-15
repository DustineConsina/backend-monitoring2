<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('tenants', 'qr_access_code')) {
            Schema::table('tenants', function (Blueprint $table) {
                $table->text('qr_access_code')->nullable()->after('qr_password_hash');
            });
        }

        DB::table('tenants')
            ->whereNull('qr_access_code')
            ->orderBy('id')
            ->get(['id'])
            ->each(function (object $tenant): void {
                $code = (string) random_int(10000000, 99999999);
                DB::table('tenants')->where('id', $tenant->id)->update([
                    'qr_access_code' => encrypt($code),
                    'qr_password_hash' => Hash::make($code),
                ]);
            });
    }

    public function down(): void
    {
        if (Schema::hasColumn('tenants', 'qr_access_code')) {
            Schema::table('tenants', function (Blueprint $table) {
                $table->dropColumn('qr_access_code');
            });
        }
    }
};
