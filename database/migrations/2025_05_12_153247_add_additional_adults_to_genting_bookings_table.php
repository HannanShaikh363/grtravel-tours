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
        Schema::table('genting_bookings', function (Blueprint $table) {
            $table->integer('additional_adults')->after('total_cost')->nullable();
            $table->integer('additional_children')->after('additional_adults')->nullable();
            $table->decimal('additional_adult_price', 8, 2)->after('additional_children')->nullable();
            $table->decimal('additional_child_price', 8, 2)->after('additional_adult_price')->nullable();
        });
    }    

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('genting_bookings', function (Blueprint $table) {
            $table->dropColumn('additional_adults');
            $table->dropColumn('additional_children');
            $table->dropColumn('additional_adult_price');
            $table->dropColumn('additional_child_price');
        });
    }
};
