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
        Schema::create('genting_hotels', function (Blueprint $table) {
            $table->id();
            $table->string('hotel_name');
            $table->unsignedBigInteger('location_id');
            $table->string('hotel_code')->nullable();
            $table->longText('descriptions')->nullable();
            $table->longText('facilities')->nullable();
            $table->longText('others')->nullable();
            $table->json('images')->nullable();
            $table->string('closing_day')->nullable();
            $table->timestamps();
            $table->foreign('location_id')
                ->references('id')
                ->on('locations')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('genting_hotels');
    }
};
