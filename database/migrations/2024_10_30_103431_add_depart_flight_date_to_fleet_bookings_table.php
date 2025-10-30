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
        Schema::table('fleet_bookings', function (Blueprint $table) {
            $table->date('depart_flight_date')->nullable()->after('arrival_airline_code');
            $table->date('arrival_flight_date')->nullable()->after('depart_flight_date');
            $table->date('return_arrival_flight_date')->nullable()->after('arrival_flight_date');
            $table->string('return_arrival_flight_number')->nullable()->after('return_arrival_flight_date');
            $table->date('return_depart_flight_date')->nullable()->after('return_arrival_flight_number');
            $table->string('return_depart_flight_number')->nullable()->after('return_depart_flight_date');
            $table->string('return_flight_departure_time')->nullable()->after('return_depart_flight_number');
            $table->string('return_flight_arrival_time')->nullable()->after('return_flight_departure_time');
            $table->string('return_pickup_date')->nullable()->after('return_flight_arrival_time');
            $table->string('return_pickup_time')->nullable()->after('return_pickup_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fleet_bookings', function (Blueprint $table) {
            $table->dropColumn([
                'depart_flight_date',
                'arrival_flight_date',
                'return_arrival_flight_date',
                'return_arrival_flight_number',
                'return_depart_flight_date',
                'return_depart_flight_number',
                'return_flight_departure_time',
                'return_flight_arrival_time',
                'return_pickup_date',
                'return_pickup_time'
            ]);
        });
    }
};
