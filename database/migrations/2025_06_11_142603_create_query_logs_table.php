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
        Schema::create('query_logs', function (Blueprint $table) {
            $table->id();
            $table->text('sql');
            $table->json('bindings')->nullable();
            $table->float('time')->nullable(); // ms
            $table->string('user_id')->nullable(); // optional
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('query_logs');
    }
};
