<?php

namespace App\Console\Commands;
use App\Models\Booking;
use App\Models\FleetBooking;
use App\Models\GentingBooking;
use App\Mail\BookingCancel;
use App\Jobs\SendEmailJob;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;


use Illuminate\Console\Command;

class CancelExpiredGentingBookings extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bookings:cancel-expired';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Cancel Expired Bookings';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $expiredBookings = Booking::whereIn('booking_status', ['confirmed'])
        ->whereIn('booking_type', ['genting_hotel'])
        ->whereNotNull('deadline_date')
        ->where('deadline_date', '<=', Carbon::now()->setTimezone('Asia/Kuala_Lumpur')->toDateTimeString())
        ->get();

        foreach ($expiredBookings as $booking) {
            $client = $booking->user;
            $fleetBooking = null;
            
            if ($booking->booking_type === 'genting_hotel') {
                $fleetBooking = GentingBooking::where('booking_id', $booking->id)->first();
            }

            $booking->update(['booking_status' => 'cancelled']);

            $cancelEmail = new BookingCancel(
                $fleetBooking,
                $client->first_name,
                null, null, null, null,
                $booking->booking_type,
                null
            );

            SendEmailJob::dispatch($client->email, $cancelEmail);
            $admin = new BookingCancel(
                $fleetBooking,
                'Admin',
                null, null, null, null,
                $booking->booking_type,
                null
            );
            $adminEmails =  [config('mail.notify_tour'), config('mail.notify_info'), config('mail.notify_account')];
            foreach ($adminEmails as $adminEmail) {
                SendEmailJob::dispatch($adminEmail, $admin);
            }
        }
    }
}
