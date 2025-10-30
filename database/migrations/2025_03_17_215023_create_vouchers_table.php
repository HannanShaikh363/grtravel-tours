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
        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->string('v_no')->unique(); // Voucher number
            $table->date('v_date'); // Voucher date
            $table->foreignId('voucher_type_id')->constrained('voucher_types')->onDelete('cascade'); // Linked to voucher types table
            $table->string('cheque_no')->nullable(); // Cheque number
            $table->date('cheque_date')->nullable(); // Cheque date
            $table->text('narration')->nullable(); // Description
            $table->decimal('total_debit', 15, 2)->default(0); // Total Debit Amount
            $table->decimal('total_credit', 15, 2)->default(0); // Total Credit Amount
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vouchers');
    }
};
