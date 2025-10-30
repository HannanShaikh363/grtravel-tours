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
            $table->boolean('created_by_admin')->default(false)->after('email_sent');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fleet_bookings', function (Blueprint $table) {
            $table->dropColumn('created_by_admin');
        });
    }
};
