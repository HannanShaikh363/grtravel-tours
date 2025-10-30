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
        Schema::create('contractual_room_details', function (Blueprint $table) {
            $table->id();
            $table->string('room_no');
            $table->unsignedBigInteger('booking_id'); // Foreign key column
            $table->foreign('booking_id')->references('id')->on('contractual_hotel_bookings')->onDelete('cascade');
            $table->integer('number_of_adults')->nullable();
            $table->integer('number_of_children')->nullable();
            $table->json('child_ages')->nullable();
            $table->integer('extra_bed_for_adult')->nullable();
            $table->integer('extra_bed_for_child')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contractual_room_details');
    }
};
