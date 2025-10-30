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
        Schema::table('device_verifications', function (Blueprint $table) {
            // Add the new columns
            $table->string('verification_token', 512)->after('ip_address');
            $table->enum('status', ['pending', 'verified', 'expired'])->default('pending')->after('verification_token');
            $table->timestamp('expires_at')->nullable()->after('status'); // Optional: Expiration time
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('device_verifications', function (Blueprint $table) {
            $table->dropColumn(['verification_token', 'status', 'expires_at']);
        });
    }
};
