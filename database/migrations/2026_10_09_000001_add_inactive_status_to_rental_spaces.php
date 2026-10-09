<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_spaces', function (Blueprint $table) {
            $table->enum('status', ['available', 'occupied', 'maintenance', 'inactive'])
                ->default('available')
                ->change();
        });
    }

    public function down(): void
    {
        \DB::table('rental_spaces')->where('status', 'inactive')->update(['status' => 'available']);

        Schema::table('rental_spaces', function (Blueprint $table) {
            $table->enum('status', ['available', 'occupied', 'maintenance'])
                ->default('available')
                ->change();
        });
    }
};
