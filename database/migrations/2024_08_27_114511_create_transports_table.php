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
        Schema::create('transports', function (Blueprint $table) {
            $table->id();
            $table->string('vehicle_make');
            $table->string('vehicle_model')->unique();
            $table->string('package')->nullable();
            $table->integer('vehicle_year_of_manufacture')->nullable();
            $table->string('vehicle_vin')->comment('Vehicle Identification Number')->nullable();
            $table->string('vehicle_license_plate_number')->nullable();
            $table->string('vehicle_color')->nullable();
            $table->string('vehicle_engine_number')->nullable();
            $table->string('vehicle_fuel_type')->comment('e.g., Petrol, Diesel, Electric')->Default('Petrol')->nullable();
            $table->string('vehicle_transmission_type')->comment('e.g., Manual, Automatic')->Default('Manual')->nullable();
            $table->string('vehicle_body_type')->comment('e.g., Sedan, SUV, Truck')->nullable();
            $table->integer('vehicle_seating_capacity')->comment('e.g., 4, 5, 7')->nullable();
            $table->integer('vehicle_luggage_capacity')->comment('e.g., 4, 5, 7')->nullable();
            $table->string('vehicle_registration_number')->nullable();
            $table->unsignedInteger('user_id')->foreign(\App\Models\User::class)->onDelete('cascade');

            $table->timestamps();
        });

        Schema::create('transports_driver', function (Blueprint $table) {
            $table->id();
            $table->string('owner_full_name')->nullable();
            $table->string('owner_address')->nullable();
            $table->string('owner_contact_number')->nullable();
            $table->string('owner_email_address')->nullable();
            $table->string('driver_full_name')->nullable();
            $table->string('driver_contact_number')->nullable();
            $table->string('driver_email_address')->nullable();
            $table->string('previous_owners')->nullable();
            $table->string('previous_owners_number')->nullable();
            $table->text('special_notes')->nullable();
            $table->unsignedInteger('transport_id')->foreign(\App\Models\Transport::class)->onDelete('cascade');
            $table->timestamps();
        });

        Schema::create('transports_insurance', function (Blueprint $table) {
            $table->id();
            $table->string('insurance_company_name')->nullable();
            $table->string('insurance_policy_number')->nullable();
            $table->date('insurance_expiry_date')->nullable();
            $table->unsignedInteger('transport_id')->foreign(\App\Models\Transport::class)->onDelete('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transports');
        Schema::dropIfExists('transports_driver');
        Schema::dropIfExists('transports_insurance');
    }
};
