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
        Schema::table('bookings', function (Blueprint $table) {
            $table->decimal('subtotal', 10, 2)->default(0)->after('amount');
            $table->decimal('tax_percent', 5, 2)->default(0)->after('subtotal');
            $table->decimal('tax_amount', 10, 2)->default(0)->after('tax_percent');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['subtotal', 'tax_percent', 'tax_amount']);
        });
    }
};
