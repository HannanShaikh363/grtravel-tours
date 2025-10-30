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
      Schema::create('hotel_surcharges', function (Blueprint $table) {
        $table->id();
        $table->foreignId('hotel_id')->constrained('contractual_hotels')->onDelete('cascade');

        // Basic Info
        $table->string('title');
        $table->enum('type', ['surcharge', 'discount']); // renamed from `type` to `surcharge_type` for clarity
        $table->unsignedInteger('minimum_nights')->default(0);

        // Validity
        $table->enum('validity_type', ['date_range', 'in_days']);
        $table->date('start_date')->nullable();          // used if validity_type is date_range
        $table->date('end_date')->nullable();            // used if validity_type is date_range
        $table->string('fixed_days')->nullable();          // used if validity_type is fixed

        // Not Applicable Dates
        $table->date('not_applicable_start')->nullable();
        $table->date('not_applicable_end')->nullable();

        // Value Info
        $table->enum('amount_type', ['amount', 'percentage']);
        $table->decimal('value', 10, 2);
        $table->string('currency')->nullable(); // nullable if percentage

            

        $table->timestamps();
    });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hotel_surcharges');
    }
};
