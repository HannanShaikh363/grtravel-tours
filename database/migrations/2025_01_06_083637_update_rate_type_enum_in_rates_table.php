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
        Schema::table('rates', function (Blueprint $table) {
            $table->enum('rate_type', [
                'airport_transfer',
                'hotel_transfer',
                'airport_to_airport',
                'accomodation_to_accomodation',
                'transit_station',   
                'bus_station',
                'train_station',
                'bus_stop'        
            ])->default('airport_transfer')->change();
        
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rates', function (Blueprint $table) {
            $table->enum('rate_type', [
                'airport_transfer',
                'hotel_transfer',
                'airport_to_airport',
                'accomodation_to_accomodation'
            ])->default('airport_transfer')->change();
        });
    }
};
