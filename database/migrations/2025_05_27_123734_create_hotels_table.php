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
        Schema::create('hotels', function (Blueprint $table) {
            $table->id();
            $table->string('hotel_name');
            $table->unsignedBigInteger('city_id');
            $table->string('rezlive_hotel_code')->nullable();
            $table->string('tbo_hotel_code')->nullable();
            $table->timestamps();
            $table->index('city_id');
            $table->index('rezlive_hotel_code');
            $table->index('tbo_hotel_code');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
       Schema::dropIfExists('hotels');
    }
};
