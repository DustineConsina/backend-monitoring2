<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('complaints', function (Blueprint $table) {
            $table->boolean('admin_read')->default(false)->after('status');
            $table->timestamp('admin_read_at')->nullable()->after('admin_read');
            $table->index(['admin_read', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('complaints', function (Blueprint $table) {
            $table->dropIndex(['admin_read', 'created_at']);
            $table->dropColumn(['admin_read', 'admin_read_at']);
        });
    }
};
