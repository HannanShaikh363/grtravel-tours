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
        Schema::create('configurations', function (Blueprint $table) {
            $table->id();
            $table->string('group'); // e.g., 'razerpay', 'aws_email', 'rezlive', 'tbo'
            $table->string('key');   // e.g., 'merchant_id', 'email', 'secret_key'
            $table->text('value')->nullable(); // Use text for large config values
            $table->timestamps();

            $table->unique(['group', 'key']);
        });

        
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('configurations');
    }
};
