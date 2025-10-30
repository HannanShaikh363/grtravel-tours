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
        Schema::create('chart_of_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('account_code', 12)->unique();
            $table->string('account_name', 255);
            $table->string('nature', 100);
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->integer('level')->default(0);
            $table->enum('type', ['Asset', 'Liability', 'Equity', 'Revenue', 'Expense'])->nullable();
            $table->string('currency', 10)->nullable();
            $table->boolean('status')->default(1);
            $table->timestamps();
            $table->foreign('parent_id')->references('id')->on('chart_of_accounts')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chart_of_accounts');
    }
};
