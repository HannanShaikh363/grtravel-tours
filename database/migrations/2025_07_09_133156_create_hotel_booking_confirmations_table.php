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
        Schema::create('hotel_booking_confirmations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('hotel_bookings')->onDelete('cascade');
            $table->string('confirmation_status')->nullable();
            $table->string('confirmation_no')->nullable();
            $table->string('confirmation_note')->nullable();
            $table->string('telephone_no')->nullable();
            $table->string('staff_name')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hotel_booking_confirmations');
    }
};
