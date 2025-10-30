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
            $table->integer('extra_bed_for_child')->default(0)->after('child_ages'); 
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('genting_room_details', function (Blueprint $table) {
            $table->dropColumn('extra_bed_for_child');
        });
    }
};
