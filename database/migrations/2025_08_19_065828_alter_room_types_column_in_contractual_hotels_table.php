<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('contractual_hotels', function (Blueprint $table) {
            $table->text('room_types')->change();
        });
        Schema::table('contractual_hotel_rates', function (Blueprint $table) {
            $table->text('entitlements')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contractual_hotels', function (Blueprint $table) {
            $table->string('room_types', 255)->change(); // revert back 
        });
        Schema::table('contractual_hotel_rates', function (Blueprint $table) {
            $table->string('entitlements', 255)->change(); // revert back 
        });
    }
};
