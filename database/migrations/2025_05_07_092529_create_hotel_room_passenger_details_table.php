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
        Schema::create('hotel_room_passenger_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('room_detail_id');
            $table->string('passenger_title')->nullable();
            $table->string('passenger_first_name');
            $table->string('passenger_last_name')->nullable();
            $table->string('phone_code')->nullable();
            $table->string('passenger_contact_number')->nullable();
            $table->string('passenger_email_address')->nullable();
            $table->unsignedBigInteger('nationality_id')->nullable(); // You can link to a countries table
            $table->timestamps();
        
            $table->foreign('room_detail_id')->references('id')->on('hotel_room_details')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hotel_room_passenger_details');
    }
};
