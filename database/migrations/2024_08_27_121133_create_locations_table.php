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
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->float('latitude');
            $table->float('longitude');
            $table->unsignedInteger('city_id')->foreign(\App\Models\City::class)->onDelete('cascade')->nullable();
            $table->unsignedInteger('country_id')->foreign(\App\Models\Country::class)->onDelete('cascade')->nullable();
            $table->unsignedInteger('user_id')->foreign(\App\Models\User::class)->onDelete('cascade');
            $table->timestamps();
        });

        Schema::create('rates', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('package');
            $table->unsignedInteger('from_location_id')->foreign(\App\Models\Location::class)->onDelete('cascade')->nullable();
            $table->string('transport_id')->foreign(\App\Models\Transport::class)->onDelete('cascade')->nullable();
            $table->integer('vehicle_seating_capacity')->comment('e.g., 4, 5, 7')->nullable();
            $table->integer('vehicle_luggage_capacity')->comment('e.g., 4, 5, 7')->nullable();
            $table->unsignedInteger('to_location_id')->foreign(\App\Models\Location::class)->onDelete('cascade');
            $table->unsignedBigInteger('child_id')->foreign(\App\Models\Location::class)->onDelete('cascade')->nullable();
            $table->decimal('rate', 10, 2);
            $table->time('hours')->nullable();
            $table->enum('route_type', ['one_way', 'two_way'])->default('one_way');
            $table->string('rate_type')->default('airport_transfer')->comment( 'airport_transfer, accomodation_transfer, train_station,transit_station');
            $table->string('currency');
            $table->date('effective_date');
            $table->date('expiry_date')->nullable();
            $table->text('time_remarks')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('locations');
        Schema::dropIfExists('rates');
    }
};
