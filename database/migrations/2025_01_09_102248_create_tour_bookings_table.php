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
        Schema::create('tour_bookings', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('location_id')->foreign(\App\Models\Location::class)->onDelete('cascade');
            $table->unsignedInteger('booking_id')->foreign(\App\Models\Booking::class)->onDelete('cascade')->nullable();
            $table->string('tour_rate_id')->foreign(\App\Models\Tour::class)->onDelete('cascade')->nullable();
            $table->unsignedInteger('user_id')->foreign(\App\Models\User::class)->onDelete('cascade');
            $table->string('passenger_full_name');
            $table->string('passenger_contact_number');
            $table->string('passenger_email_address')->nullable();
            $table->dateTime('booking_date');
            $table->date('tour_date');
            $table->time('tour_time');
            $table->string('total_cost');
            $table->string('currency');
            $table->integer('number_of_adults')->nullable();
            $table->integer('number_of_children')->nullable();
            $table->integer('seating_capacity');
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
        Schema::dropIfExists('tour_bookings');
    }
};
