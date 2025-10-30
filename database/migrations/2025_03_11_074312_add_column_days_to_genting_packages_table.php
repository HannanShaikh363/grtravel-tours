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
        Schema::table('genting_packages', function (Blueprint $table) {
            $table->integer('days')->default(0)->after('package');
            $table->integer('nights')->default(0)->after('days');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('genting_packages', function (Blueprint $table) {
            $table->dropColumn('days');
            $table->dropColumn('nights');
        });
    }
};
