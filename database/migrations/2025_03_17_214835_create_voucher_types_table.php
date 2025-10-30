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
        Schema::create('voucher_types', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // Example: SV-J, GV-J, CV-R
            $table->string('name'); // Example: "Sales Voucher Journal"
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // Insert default voucher types
        \DB::table('voucher_types')->insert([
            ['code' => 'SV-J', 'name' => 'Sales Voucher Journal', 'description' => 'Sales-related transactions'],
            ['code' => 'GV-J', 'name' => 'General Voucher Journal', 'description' => 'General ledger transactions'],
            ['code' => 'CV-R', 'name' => 'Cash Voucher Receipt', 'description' => 'Cash received'],
            ['code' => 'BV-R', 'name' => 'Bank Voucher Receipt', 'description' => 'Cash received in Bank'],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('voucher_types');
    }
};
