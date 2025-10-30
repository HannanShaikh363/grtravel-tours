<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        //
        Schema::table('locations', function (Blueprint $table) {
            $table->string('location_type')->default('other')->comment('airport,hotel,city,other');
        });
        Schema::create('meeting_point', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('location_id');
            $table->foreign('location_id')->references('id')->on('locations');
            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id')->references('id')->on('users');
            $table->text('meeting_point_name');
            $table->text('meeting_point_desc');
            $table->string('meeting_point_type')->default('other')->comment('airport,hotel,city,other');
            $table->string('meeting_point_attachment')->nullable();
            $table->smallInteger('active')->default(1);
            $table->timestamps();

        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //

        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn('location_type');
        });
        Schema::dropIfExists('meeting_point');
    }
};
