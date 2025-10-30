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
            $table->string('tour_name')->nullable()->after('user_id');
            $table->string('hours')->nullable()->after('tour_name');
            $table->string('nationality_id')->nullable()->after('hours');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tour_bookings', function (Blueprint $table) {
            $table->dropColumn('tour_name');
            $table->dropColumn('location_id');
            $table->dropColumn('hours');
            $table->dropColumn('nationality_id');
        });
    }
};
