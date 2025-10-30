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
        Schema::dropIfExists('tours');
        Schema::create('tours', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('package');
            $table->string('location_name');
            $table->unsignedInteger('location_id')->foreign(\App\Models\Location::class)->onDelete('cascade');
            $table->string('price');
            $table->string('currency');
            $table->integer('seating_capacity')->comment('e.g., 4, 5, 7')->nullable();
            $table->integer('luggage_capacity')->comment('e.g., 4, 5, 7')->nullable();
            $table->string('adult')->nullable();
            $table->string('child')->nullable();
            $table->time('hours')->nullable();
            $table->date('effective_date');
            $table->date('expiry_date')->nullable();
            $table->timestamps();
        });

        Schema::create('tours_booking', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('location_id')->foreign(\App\Models\Location::class)->onDelete('cascade');
            $table->string(column: 'parent_id')->foreign(\App\Models\Tour::class)->onDelete('cascade');
            $table->string('tour_id')->foreign(\App\Models\Tour::class)->onDelete('cascade')->nullable();
            $table->unsignedInteger('user_id')->foreign(\App\Models\User::class)->onDelete('cascade');
            $table->string('passenger_full_name');
            $table->string('passenger_contact_number');
            $table->string('passenger_email_address');
            $table->dateTime('booking_date');
            $table->date('tour_date');
            $table->string('total_cost');
            $table->string('flight_number');
            // $table->string('iata_code');
            $table->string('airline_iata');
            $table->time('flight_arrival_time');
            $table->string('nationality')->nullable();
            $table->integer('number_of_adults')->nullable();
            $table->integer('number_of_children')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tours');
        Schema::dropIfExists('tours_booking');
    }
};
