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
            $table->string('confirmation_id')->nullable()->after('booking_id');
            $table->string('reservation_id')->nullable()->after('confirmation_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('genting_bookings', function (Blueprint $table) {
            $table->dropColumn(['confirmation_id', 'reservation_id']);
        });
    }
};
