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
            $table->date('closing_start_date')->nullable()->after('closing_day');
            $table->date('closing_end_date')->nullable()->after('closing_start_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tour_destinations', function (Blueprint $table) {
            $table->dropColumn(['closing_start_date', 'closing_end_date']);
        });
    }
};
