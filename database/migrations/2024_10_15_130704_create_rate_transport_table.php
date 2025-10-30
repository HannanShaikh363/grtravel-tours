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
        Schema::create('rate_transport', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rate_id')->constrained('rates')->onDelete('cascade');
            $table->foreignId('transport_id')->constrained('transports')->onDelete('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rate_transport');
    }
};
