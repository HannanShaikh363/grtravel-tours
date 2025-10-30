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
            $table->string('booking_unique_id')->unique()->nullable()->after('id');
        });

        // Generate unique booking IDs for existing records
        $prefixes = [
            'transfer' => 'GRT',
            'tour' => 'GRTU',
            'flight' => 'GRF',
            'hotel' => 'GRH',
            'genting_hotel' => 'GRGH',
        ];

        $bookings = \App\Models\Booking::all();

        foreach ($bookings as $booking) {
            $prefix = $prefixes[$booking->booking_type] ?? 'GRX';
            $date = \Carbon\Carbon::parse($booking->created_at)->format('ymd');
            $count = str_pad($booking->id, 3, '0', STR_PAD_LEFT);
            $booking->booking_unique_id = "{$prefix}-{$date}-{$count}";
            $booking->save();
        }

        Schema::table('bookings', function (Blueprint $table) {
            $table->string('booking_unique_id')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('booking_unique_id');
        });
    }
};
