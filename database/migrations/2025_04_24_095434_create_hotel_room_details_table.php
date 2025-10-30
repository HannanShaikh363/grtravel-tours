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
        Schema::create('hotel_room_details', function (Blueprint $table) {
            $table->id();
            $table->string('room_no');
            $table->unsignedBigInteger('booking_id'); // Foreign key column
            $table->foreign('booking_id')->references('id')->on('hotel_bookings')->onDelete('cascade');
            $table->string('passenger_title');
            $table->string('passenger_first_name');
            $table->string('passenger_last_name');
            $table->string('phone_code')->nullable();
            $table->string('passenger_contact_number')->nullable();
            $table->string('passenger_email_address')->nullable();
            $table->string('nationality_id')->nullable();
            $table->integer('number_of_adults')->nullable();
            $table->integer('number_of_children')->nullable();
            $table->json('child_ages')->nullable();
            $table->integer('extra_bed_for_child')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hotel_room_details');
    }
};
