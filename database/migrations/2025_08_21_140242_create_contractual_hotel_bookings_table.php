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
        Schema::create('contractual_hotel_bookings', function (Blueprint $table) {
           $table->id();
            $table->unsignedInteger('country_id')->foreign(\App\Models\Country::class)->onDelete('cascade')->nullable();
            $table->unsignedInteger('city_id')->foreign(\App\Models\City::class)->onDelete('cascade')->nullable();
            $table->string('rate_id')->foreign(\App\Models\ContractualHotelRate::class)->onDelete('cascade')->nullable();
            $table->string('hotel_id')->foreign(\App\Models\ContractualHotel::class)->onDelete('cascade')->nullable();
            $table->unsignedInteger('booking_id')->foreign(\App\Models\Booking::class)->onDelete('cascade')->nullable();
            $table->unsignedInteger('user_id')->foreign(\App\Models\User::class)->onDelete('cascade');
            $table->string('hotel_name');
            $table->string('check_in');
            $table->string('check_out');
            $table->string('total_cost');
            $table->string('currency');
            $table->string('room_type');
            $table->integer('number_of_rooms');
             $table->integer('room_capacity');
            $table->integer('extra_beds_adult');
            $table->integer('extra_beds_child');
            $table->integer('extra_amount_adult_bed');
            $table->integer('extra_amount_child_bed');
            $table->boolean('approved')->default(false);
            $table->boolean('sent_approval')->default(false);
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
        Schema::dropIfExists('contractual_hotel_bookings');
    }
};
