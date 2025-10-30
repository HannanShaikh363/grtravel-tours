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
        Schema::create('contractual_hotel_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained('contractual_hotels')->onDelete('cascade');
            $table->string('room_type');
            $table->string('weekdays_price');
            $table->string('weekend_price');
            $table->string('currency');
            $table->string('entitlements');
            $table->string('no_of_beds');
            $table->string('room_capacity');
            $table->date('effective_date');
            $table->date('expiry_date')->nullable();
             $table->text('images')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contractual_hotel_rates');
    }
};
