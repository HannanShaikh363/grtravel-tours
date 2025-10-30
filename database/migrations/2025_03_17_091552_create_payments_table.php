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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('reference_id')->nullable();
            $table->string('module'); 
            $table->string('payment_method');
            $table->decimal('amount', 10, 2);
            $table->enum('transaction_type', ['Credit', 'Debit']);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamp('transaction_date');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
