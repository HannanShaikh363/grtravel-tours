<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('discount_vouchers', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // e.g. SAVE20
            $table->enum('type', ['fixed', 'percentage']); // Discount type
            $table->decimal('value', 10, 2); // Discount amount
            $table->string('currency', 3)->nullable(); // For fixed discount, e.g., USD
            $table->dateTime('valid_from')->nullable();
            $table->dateTime('valid_until')->nullable();

            $table->integer('usage_limit')->nullable(); // total times it can be used
            $table->integer('used_count')->default(0); // how many times used globally
            $table->integer('per_user_limit')->nullable(); // max times one user can use it

            $table->decimal('min_booking_amount', 10, 2)->nullable(); // minimum order
            $table->decimal('max_discount_amount', 10, 2)->nullable(); // for percentage limit

            $table->json('applicable_to')->nullable(); // e.g. ["flights", "hotels"]
            $table->boolean('is_public')->default(true); // public or user-restricted

            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->unsignedBigInteger('created_by')->nullable(); // admin_id
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('discount_vouchers');
    }
};
