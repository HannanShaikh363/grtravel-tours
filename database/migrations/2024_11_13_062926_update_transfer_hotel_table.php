<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::table('transfer_hotel', function (Blueprint $table) {
            // Rename hotel_name to pickup_hotel_name
            $table->renameColumn('hotel_name', 'pickup_hotel_name');
            // Add dropoff_hotel_name column
            $table->string('dropoff_hotel_name')->nullable()->after('pickup_hotel_name');
        });
    }
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transfer_hotel', function (Blueprint $table) {
            // Revert the column name change
            $table->renameColumn('pickup_hotel_name', 'hotel_name');
            // Drop dropoff_hotel_name column
            $table->dropColumn('dropoff_hotel_name');
        });
    }
};
