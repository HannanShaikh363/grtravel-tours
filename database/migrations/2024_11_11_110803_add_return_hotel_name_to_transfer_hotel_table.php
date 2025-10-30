<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddReturnHotelNameToTransferHotelTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('transfer_hotel', function (Blueprint $table) {
            $table->string('return_dropoff_hotel_name')->nullable()->after('hotel_name'); // Adds the return_dropoff_hotel_name column
            $table->string('return_pickup_hotel_name')->nullable()->after('return_dropoff_hotel_name'); // Adds the return_pickup_hotel_name column
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transfer_hotel', function (Blueprint $table) {
            $table->dropColumn('return_dropoff_hotel_name'); // Drops the return_dropoff_hotel_name column
            $table->dropColumn('return_pickup_hotel_name'); // Drops the return_pickup_hotel_name column
        });
    }
}
