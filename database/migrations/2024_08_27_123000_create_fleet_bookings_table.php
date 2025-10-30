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
        Schema::create('fleet_bookings', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('transport_id')->foreign(\App\Models\Transport::class)->onDelete('cascade')->nullable();
            $table->unsignedInteger('agent_id')->foreign(\App\Models\User::class)->onDelete('cascade')->nullable();
            $table->unsignedInteger('user_id')->foreign(\App\Models\User::class)->onDelete('cascade');
            $table->string('transfer_name');
            $table->unsignedInteger('from_location_id')->foreign(\App\Models\Location::class)->onDelete('cascade');
            $table->unsignedInteger('to_location_id')->foreign(\App\Models\Location::class)->onDelete('cascade');
            $table->unsignedInteger('nationality_id')->foreign(\App\Models\Location::class)->onDelete('cascade')->nullable();
            $table->dateTime('booking_date')->nullable();
            $table->date('pick_date');
            $table->time('pick_time');
            $table->integer('vehicle_seating_capacity')->comment('e.g., 4, 5, 7');
            $table->integer('vehicle_luggage_capacity')->comment('e.g., 4, 5, 7');
            $table->dateTime('dropoff_date')->nullable();
            $table->unsignedInteger('rate_id')->foreign(\App\Models\Rate::class)->onDelete('cascade')->nullable();
            $table->string('total_cost')->nullable();
            $table->string('booking_cost');
            $table->string('passenger_title');
            $table->string('passenger_full_name');
            $table->string('passenger_contact_number');
            $table->string('passenger_email_address');
            $table->string('hours')->nullable();
            $table->string('journey_type')->nullable();
            $table->string('vehicle_model')->nullable();
            $table->string('vehicle_make')->nullable();
            $table->string('depart_flight_number')->nullable();
            $table->string('depart_airline_code')->nullable();
            $table->string('arrival_flight_number')->nullable();
            $table->string('arrival_airline_code')->nullable();
            $table->string('flight_departure_time')->nullable();
            $table->string('flight_arrival_time')->nullable();
            $table->boolean('approved')->default(false);
            $table->boolean('email_sent')->default(false);
            $table->boolean('created_by_admin')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fleet_bookings');
    }
};
