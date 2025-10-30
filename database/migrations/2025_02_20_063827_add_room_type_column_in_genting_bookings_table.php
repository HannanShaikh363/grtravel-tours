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
        Schema::table('genting_bookings', function (Blueprint $table) {
            $table->string('room_type')->nullable()->after('hotel_name');
            $table->string('number_of_rooms')->nullable()->after('room_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('genting_bookings', function (Blueprint $table) {
            $table->dropColumn('room_type');
            $table->dropColumn('number_of_rooms');
        });
    }
};
