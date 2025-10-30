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
        Schema::table('fleet_bookings', function (Blueprint $table) {
            $table->string('arrival_terminal')->nullable()->after('meeting_point');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fleet_bookings', function (Blueprint $table) {
            $table->dropColumn('arrival_terminal');
        });
    }
};
