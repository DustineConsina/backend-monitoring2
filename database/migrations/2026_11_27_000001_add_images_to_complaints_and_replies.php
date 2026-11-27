<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('complaints', function (Blueprint $table) {
            $table->string('image_path')->nullable();
        });

        Schema::table('complaint_replies', function (Blueprint $table) {
            $table->string('image_path')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('complaint_replies', function (Blueprint $table) {
            $table->dropColumn('image_path');
        });

        Schema::table('complaints', function (Blueprint $table) {
            $table->dropColumn('image_path');
        });
    }
};
