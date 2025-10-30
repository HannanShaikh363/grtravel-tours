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
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->unique();
            $table->string('username')->unique();
            $table->string('phone');
            $table->timestamp('email_verified_at')->nullable();
            $table->string('fax')->nullable();
            $table->string('mobile')->nullable();
            $table->string('password');
            $table->string('type')->default('agent');
            $table->string('designation')->nullable();
            $table->string('preferred_currency')->nullable();
            $table->boolean('created_by_admin')->default(false);
            $table->boolean('approved')->default(false);
            $table->rememberToken();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('cities');
        Schema::dropIfExists('countries');
    }
};
