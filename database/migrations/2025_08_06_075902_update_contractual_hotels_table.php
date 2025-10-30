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
        Schema::table('contractual_hotels', function (Blueprint $table) {
             if (Schema::hasColumn('contractual_hotels', 'location')) {
                $table->dropColumn('location');
            }
           $table->unsignedBigInteger('city_id')->after('description')->nullable();
            $table->unsignedBigInteger('country_id')->after('city_id')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
         Schema::table('contractual_hotels', function (Blueprint $table) {
            // Rollback: add location back
            $table->string('location')->nullable();

            // Drop new columns
            $table->dropColumn(['city_id', 'country_id']);
        });
    }
};
