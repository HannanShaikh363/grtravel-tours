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
        Schema::create('genting_rates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('genting_package_id');
            $table->string('room_type');
            $table->decimal('price', 10, 2);
            $table->string('currency', 10);
            $table->json('entitlements')->nullable();
            $table->json('bed_count')->nullable();
            $table->string('room_capacity', 10)->nullable();
            $table->date('effective_date');
            $table->date('expiry_date')->nullable();
            $table->json('images')->nullable();
            $table->unsignedBigInteger('genting_hotel_id');
            $table->timestamps();
            $table->foreign('genting_hotel_id')
                ->references('id')
                ->on('genting_hotels')
                ->onDelete('cascade');
            $table->foreign('genting_package_id')
                ->references('id')
                ->on('genting_packages')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('genting_rates');
    }
};
