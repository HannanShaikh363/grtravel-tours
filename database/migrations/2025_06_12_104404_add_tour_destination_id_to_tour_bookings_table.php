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
        Schema::table('tour_bookings', function (Blueprint $table) {
            // Add the foreign key column
            $table->unsignedBigInteger('tour_destination_id')->nullable()->after('tour_rate_id');

            // Define the foreign key constraint
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
        Schema::table('tour_bookings', function (Blueprint $table) {
            // Drop the foreign key and column
            $table->dropForeign(['tour_destination_id']);
            $table->dropColumn('tour_destination_id');
        });
    }
};
