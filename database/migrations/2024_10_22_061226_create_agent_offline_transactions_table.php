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
        Schema::create('agent_offline_transactions', function (Blueprint $table) {
            $table->id();
            $table->float('amount');
            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id')->references('id')->on('users');
            $table->string('transaction_type')->comment('Transfer,Flight,Tour and Hotel');
            $table->timestamps();
        });


        Schema::create('agent_credit_limit_added', function (Blueprint $table) {
            $table->id();
            $table->float('amount');
            $table->string('currency')->default( 'USD')->comment('GBP,MYR,THB,SGD,USD');
            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id')->references('id')->on('users');
            $table->unsignedBigInteger('agent_id');
            $table->foreign('agent_id')->references('id')->on('users');
            $table->smallInteger('active')->default(0);
            $table->timestamps();
        });


        Schema::create('agent_pricing_adjustments', function (Blueprint $table) {
            $table->id();
            $table->float('percentage');
            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id')->references('id')->on('users');
            $table->unsignedBigInteger('agent_id');
            $table->foreign('agent_id')->references('id')->on('users');
            $table->string('percentage_type')->default('surcharge')->comment('surcharge or discount');
            $table->string('transaction_type')->comment('Transfer,Flight,Tour and Hotel');
            $table->datetime('effective_date')->nullable();
            $table->datetime('expiration_date')->nullable();
            $table->smallInteger('active')->default(0);
            $table->timestamps();
        });


        Schema::table('users', function (Blueprint $table) {
            $table->decimal('credit_limit',10,3)->default(0);
            $table->string('credit_limit_currency')->default('USD');
            $table->boolean('email_sent')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_offline_transactions');
        Schema::dropIfExists('agent_credit_limit_added');
        Schema::dropIfExists('agent_pricing_adjustments');
       Schema::dropColumns('users', ['credit_limit', 'credit_limit_currency','email_sent']);
    }
};
