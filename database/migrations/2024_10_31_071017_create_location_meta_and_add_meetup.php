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
        Schema::table('locations', function (Blueprint $table) {
            $table->text('location_meta')->nullable();
        });

        Schema::table('meeting_point', function (Blueprint $table) {
            $table->integer('terminal')->nullable();
            $table->text('airport_areas')->nullable();
            if (Schema::hasColumn('meeting_point', 'meeting_point_attachment')) {

                $table->dropColumn('meeting_point_attachment');
            }
            $table->text('meeting_point_attachments');
        });

        Schema::table('fleet_bookings', function (Blueprint $table) {
            $table->text('pickup_meta')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {

        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn('location_meta');
        });
        Schema::table('fleet_bookings', function (Blueprint $table) {
            $table->dropColumn('pickup_meta');
        });

        Schema::table('meeting_point', function (Blueprint $table) {
            $table->dropColumn('terminal');
            $table->dropColumn('airport_areas');
            $table->dropColumn('meeting_point_attachments');
        });
    }
};
