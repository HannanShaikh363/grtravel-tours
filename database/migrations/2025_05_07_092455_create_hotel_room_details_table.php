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
            $table->unsignedBigInteger('booking_id');
            $table->unsignedInteger('number_of_adults')->default(1);
            $table->unsignedInteger('number_of_children')->default(0);
            $table->json('child_ages')->nullable(); // Or use a separate table if you want each age as a row
            $table->boolean('extra_bed_for_child')->default(false);
            $table->timestamps();
            $table->foreign('booking_id')->references('id')->on('hotel_bookings')->onDelete('cascade');
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
