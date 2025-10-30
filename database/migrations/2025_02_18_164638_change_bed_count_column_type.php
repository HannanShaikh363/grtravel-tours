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
        Schema::table('genting_rates', function (Blueprint $table) {
            // Convert JSON column to string
            $table->text('bed_count')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('genting_rates', function (Blueprint $table) {
            // Convert JSON column to string
            $table->json('bed_count')->change();
        });
       
    }
};
