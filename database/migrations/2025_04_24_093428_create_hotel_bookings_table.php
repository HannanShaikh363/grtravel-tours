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
        Schema::create('hotel_bookings', function (Blueprint $table) {
            $table->id();
            $table->string('location')->nullable();
            $table->unsignedInteger('booking_id')->foreign(\App\Models\Booking::class)->onDelete('cascade')->nullable();
            $table->string('confirmation_id')->nullable();
            $table->string('reservation_id')->nullable();
            $table->string('booking_type')->default('local')->comment('rezlive,tbo');
            // $table->string('genting_rate_id')->foreign(\App\Models\GentingRate::class)->onDelete('cascade')->nullable();
            $table->unsignedInteger('user_id')->foreign(\App\Models\User::class)->onDelete('cascade');
            $table->string('hotel_name');
            $table->string('room_type')->nullable();
            $table->string('number_of_rooms')->nullable();
            $table->string('check_in');
            $table->string('check_out');
            $table->string('total_cost');
            $table->string('currency');
            // $table->integer('room_capacity');
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
        Schema::dropIfExists('hotel_bookings');
    }
};
