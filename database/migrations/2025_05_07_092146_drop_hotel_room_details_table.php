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
        Schema::dropIfExists('hotel_room_details');
    }

    /**
     * Reverse the migrations.
     */
    public function down()
    {
        Schema::create('hotel_room_details', function (Blueprint $table) {
            $table->id();
            $table->string('room_no');
            $table->unsignedBigInteger('booking_id');
            $table->string('passenger_title')->nullable();
            $table->string('passenger_first_name');
            $table->string('passenger_last_name')->nullable();
            $table->string('phone_code')->nullable();
            $table->string('passenger_contact_number')->nullable();
            $table->string('passenger_email_address')->nullable();
            $table->unsignedBigInteger('nationality_id')->nullable();
            $table->unsignedInteger('number_of_adults')->default(1);
            $table->unsignedInteger('number_of_children')->default(0);
            $table->json('child_ages')->nullable();
            $table->boolean('extra_bed_for_child')->default(false);
            $table->timestamps();
        });
    }
};
