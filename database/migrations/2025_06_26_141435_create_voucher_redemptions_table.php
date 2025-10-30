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
        Schema::create('voucher_redemptions', function (Blueprint $table) {
            $table->id();
              $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('voucher_id')->constrained('discount_vouchers')->onDelete('cascade');
            $table->foreignId('booking_id')->constrained()->onDelete('cascade');
            $table->decimal('discount_amount', 10, 2);
            $table->dateTime('redeemed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'voucher_id', 'booking_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('voucher_redemptions');
    }
};
