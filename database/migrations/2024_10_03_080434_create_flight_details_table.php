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
        Schema::create('flight_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('tourBooking_id')->foreign(\App\Models\ToursBooking::class)->onDelete('cascade');
            $table->string('flight_number');
            $table->string('iata_code');
            $table->time('arrival_time');
            $table->time('departure_time');
            $table->string('aircraft_model');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flight_details');
    }
};
