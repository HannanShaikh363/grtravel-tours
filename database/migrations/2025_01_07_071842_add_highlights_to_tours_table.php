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
        Schema::table('tours', function (Blueprint $table) {
            $table->longText('highlights')->nullable()->after('description');
            $table->longText('important_info')->nullable()->after('highlights');
            $table->string('images')->nullable()->after('important_info');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tours', function (Blueprint $table) {
            $table->dropColumn('highlights');
            $table->dropColumn('important_info');
            $table->dropColumn('images');
        });
    }
};
