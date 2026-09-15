<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('tenants', 'tin') && !Schema::hasColumn('tenants', 'business_permit_number')) {
            Schema::table('tenants', function (Blueprint $table) {
                $table->renameColumn('tin', 'business_permit_number');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tenants', 'business_permit_number') && !Schema::hasColumn('tenants', 'tin')) {
            Schema::table('tenants', function (Blueprint $table) {
                $table->renameColumn('business_permit_number', 'tin');
            });
        }
    }
};
