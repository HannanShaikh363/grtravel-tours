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
            $table->string('pickup_address')->after('tour_time');
            $table->time('pickup_time')->nullable()->after('pickup_address');
            $table->longText('special_request')->nullable()->after('seating_capacity');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tour_bookings', function (Blueprint $table) {
            $table->dropColumn('pickup_address');
            $table->dropColumn('pickup_time');
            $table->dropColumn('special_request');
        });
    }
};
