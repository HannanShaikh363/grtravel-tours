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
        Schema::create('finance_contacts', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id')->foreign(\App\Models\User::class)->onDelete('cascade');
            $table->unsignedInteger('company_id')->foreign(\App\Models\Company::class)->onDelete('cascade');
            $table->string("account_name");
            $table->string("account_email");
            $table->string("account_contact");
            $table->string("reservation_name")->nullable();
            $table->string("reservation_email")->nullable();
            $table->string("reservation_contact")->nullable();
            $table->string("management_name")->nullable();
            $table->string("management_email")->nullable();
            $table->string("management_contact")->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('finance_contacts');
    }
};
