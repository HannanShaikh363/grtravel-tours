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
        Schema::table('tour_destinations', function (Blueprint $table) {
            $table->boolean('sharing')->default(0)->after('on_request');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tour_destinations', function (Blueprint $table) {
            $table->dropColumn('sharing');
        });
    }
};
