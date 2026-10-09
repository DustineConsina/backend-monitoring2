<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sms_messages', function (Blueprint $table) {
            $table->foreignId('payment_id')->nullable()->after('recipient_user_id')->constrained()->nullOnDelete();
            $table->foreignId('contract_id')->nullable()->after('payment_id')->constrained()->nullOnDelete();
            $table->text('error_message')->nullable()->after('status');
            $table->index(['payment_id', 'created_at']);
            $table->index(['contract_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('sms_messages', function (Blueprint $table) {
            $table->dropIndex(['payment_id', 'created_at']);
            $table->dropIndex(['contract_id', 'created_at']);
            $table->dropIndex(['status', 'created_at']);
            $table->dropConstrainedForeignId('payment_id');
            $table->dropConstrainedForeignId('contract_id');
            $table->dropColumn('error_message');
        });
    }
};
