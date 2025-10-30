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
        Schema::table('genting_add_breakfasts', function (Blueprint $table) {
            $table->string('currency')->after('hotel_id')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('genting_add_breakfasts', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }
};
