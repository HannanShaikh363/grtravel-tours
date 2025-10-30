<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (Schema::hasColumn('bookings', 'net_rate')) {
                $table->renameColumn('net_rate', 'original_rate');
            }

            if (Schema::hasColumn('bookings', 'net_currency')) {
                $table->renameColumn('net_currency', 'original_rate_currency');
            }

            if (Schema::hasColumn('bookings', 'net_rate_conversion')) {
                $table->renameColumn('net_rate_conversion', 'original_rate_conversion');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down()
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (Schema::hasColumn('bookings', 'original_rate')) {
                $table->renameColumn('original_rate', 'net_rate');
            }

            if (Schema::hasColumn('bookings', 'original_rate_currency')) {
                $table->renameColumn('original_rate_currency', 'net_currency');
            }

            if (Schema::hasColumn('bookings', 'original_rate_conversion')) {
                $table->renameColumn('original_rate_conversion', 'net_rate_conversion');
            }
        });
    }
};
