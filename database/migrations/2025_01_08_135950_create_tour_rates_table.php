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
        Schema::create('tour_rates', function (Blueprint $table) {

            $table->id();
            $table->string('package');
            $table->decimal('price', 10, 2);
            $table->string('currency', 10);
            $table->integer('seating_capacity')->default(0);
            $table->integer('luggage_capacity')->default(0);
            $table->text('remarks')->nullable();
            $table->date('effective_date');
            $table->date('expiry_date')->nullable();
            $table->unsignedBigInteger('tour_destination_id');
            $table->timestamps();
            $table->foreign('tour_destination_id')
            ->references('id')
            ->on('tour_destinations')
            ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tour_rates');
    }
};
