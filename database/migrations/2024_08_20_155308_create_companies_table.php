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
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('city_id')->foreign(\App\Models\City::class)->onDelete('cascade');
            $table->unsignedInteger('country_id')->foreign(\App\Models\Country::class)->onDelete('cascade');
            $table->string('agent_name');
            $table->string('address');
            $table->string('agent_number');
            $table->string('zip');
            $table->string('agent_website')->nullable();
            $table->string('iata_number')->nullable();
            $table->boolean('iata_status')->nullable();
            $table->string('nature_of_business')->nullable();
            $table->string('logo')->nullable();
            $table->unsignedInteger('user_id')->foreign(\App\Models\User::class)->onDelete('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
