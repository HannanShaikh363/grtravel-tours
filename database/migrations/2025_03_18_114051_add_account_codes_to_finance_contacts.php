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
        Schema::table('finance_contacts', function (Blueprint $table) {
            $table->string('account_code')->nullable()->after('account_name'); // Replace 'existing_column_name' with the relevant column
            $table->string('sales_account_code')->nullable()->after('account_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('finance_contacts', function (Blueprint $table) {
            $table->dropColumn(['account_code', 'sales_account_code']);
        });
    }
};
