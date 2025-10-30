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
        Schema::create('tour_destinations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('location_id');
            $table->time('hours')->nullable();
            $table->longText('description')->nullable();
            $table->longText('highlights')->nullable();
            $table->longText('important_info')->nullable();
            $table->string('images')->nullable();
            $table->string('closing_day')->nullable();
            $table->string('adult')->nullable();
            $table->string('child')->nullable();
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
        Schema::dropIfExists('tour_destinations');
    }
};
