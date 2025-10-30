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
        Schema::create('agent_company_finance', function (Blueprint $table) {
            $table->id();
    
            // Foreign key for agent, referencing users.id
            $table->foreignId('agent_id')->constrained('users')->onDelete('cascade');
            
            // Foreign key for company, referencing companies.id
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            
            // Foreign key for finance, referencing finance_contacts.id
            $table->foreignId('finance_id')->constrained('finance_contacts')->onDelete('cascade');
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_company_finance');
    }
};
