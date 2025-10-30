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
        Schema::create('discount_voucher_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('voucher_id')->constrained('discount_vouchers')->onDelete('cascade');
            $table->integer('usage_count')->default(0);
            $table->dateTime('assigned_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'voucher_id']); // Prevent duplicate entries for same user/voucher pair
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('discount_voucher_users');
    }
};
