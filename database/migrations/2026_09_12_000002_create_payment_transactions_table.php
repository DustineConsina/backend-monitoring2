<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->onDelete('cascade');
            $table->decimal('amount', 10, 2);
            $table->date('paid_date');
            $table->string('payment_method');
            $table->string('payment_provider')->nullable();
            $table->string('reference_number')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index(['payment_id', 'paid_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
    }
};