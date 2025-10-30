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
        Schema::create('voucher_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voucher_id')->constrained('vouchers')->onDelete('cascade'); // Parent voucher reference
            $table->string('account_code', 12); // Account Code from Chart of Accounts
            $table->text('narration')->nullable(); // Description for each entry
            $table->decimal('debit_pkr', 15, 2)->default(0); // Debit Amount in PKR
            $table->decimal('credit_pkr', 15, 2)->default(0); // Credit Amount in PKR
            $table->decimal('debit_forn', 15, 2)->default(0)->nullable(); // Debit Amount in Foreign Currency
            $table->decimal('credit_forn', 15, 2)->default(0)->nullable(); // Credit Amount in Foreign Currency
            $table->string('currency', 10)->nullable(); // Currency Code
            $table->decimal('exchange_rate', 10, 4)->default(1); // Exchange Rate
            $table->timestamps();

            // Foreign key constraint for Account Code (Chart of Accounts)
            $table->foreign('account_code')->references('account_code')->on('chart_of_accounts')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('voucher_details');
    }
};
