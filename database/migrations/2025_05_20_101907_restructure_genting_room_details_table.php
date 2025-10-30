<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
   public function up(): void
{
    // Drop old backup table if it exists
    if (Schema::hasTable('old_genting_room_details')) {
        Schema::drop('old_genting_room_details');
    }

    // Drop FK and rename existing table
    if (Schema::hasTable('genting_room_details')) {
        try {
            Schema::table('genting_room_details', function (Blueprint $table) {
                $table->dropForeign(['booking_id']);
            });
        } catch (\Illuminate\Database\QueryException $e) {
            // FK might not exist; ignore
        }

        Schema::rename('genting_room_details', 'old_genting_room_details');
    }

    // Create new table
    Schema::create('genting_room_details', function (Blueprint $table) {
        $table->id();
        $table->string('room_no');
        $table->unsignedBigInteger('booking_id');
        $table->unsignedInteger('number_of_adults')->default(1);
        $table->unsignedInteger('number_of_children')->default(0);
        $table->json('child_ages')->nullable();
        $table->boolean('extra_bed_for_child')->default(false);
        $table->timestamps();

        $table->foreign('booking_id')->references('id')->on('genting_bookings')->onDelete('cascade');
    });
}


    public function down(): void
    {
        // Drop the new table
        Schema::dropIfExists('genting_room_details');

        // Restore old table if it exists
        if (Schema::hasTable('old_genting_room_details')) {
            Schema::rename('old_genting_room_details', 'genting_room_details');
        }
    }
};

