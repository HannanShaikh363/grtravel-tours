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
        Schema::create('contractual_hotels', function (Blueprint $table) {
            $table->id();
            $table->string('hotel_name');
            $table->text('description')->nullable();
            $table->string('location')->nullable();
            $table->text('property_amenities')->nullable();
            $table->text('room_features')->nullable();
            $table->string('room_types')->nullable();
            $table->text('important_info')->nullable();
            $table->text('images')->nullable();
            $table->string('extra_bed_adult');
            $table->string('extra_bed_child');
            $table->string('currency');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contractual_hotels');
    }
};
