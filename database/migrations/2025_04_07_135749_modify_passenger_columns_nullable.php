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
        Schema::table('genting_room_details', function (Blueprint $table) {
            // Make passenger_title nullable
            $table->string('passenger_title')->nullable()->change();

            // Make passenger_full_name nullable
            $table->string('passenger_full_name')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('genting_room_details', function (Blueprint $table) {
           $table->string('passenger_title')->nullable(false)->change();
           $table->string('passenger_full_name')->nullable(false)->change();
        });
    }
};
