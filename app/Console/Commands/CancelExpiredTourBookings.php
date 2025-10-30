<?php

namespace App\Console\Commands;
use App\Mail\PendingPaymentReminderMail;
use App\Models\Booking;
use App\Models\FleetBooking;
use App\Models\GentingBooking;
use App\Mail\BookingCancel;
use App\Jobs\SendEmailJob;
use App\Models\TourBooking;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;


use Illuminate\Console\Command;

class CancelExpiredTourBookings extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bookings:cancel-expired-tour-bookings';

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

        $timeIntervals = [48, 36]; // Hours before service date

        foreach ($timeIntervals as $hours) {
            $start = Carbon::now()->addHours($hours)->startOfDay();
            $end = Carbon::now()->addHours($hours)->endOfDay();

            // $this->info("⏰ [$hours hrs] Checking bookings between $start and $end");

            $pendingBookings = Booking::whereIn('booking_status', ['confirmed'])
                ->where('created_at', '<=', Carbon::now()->subDay())
                ->whereBetween('service_date', [$start, $end])
                ->whereIn('booking_type', ['tour', 'ticket'])
                ->with(['user'])
                ->get();

            // Log::info("📌 [$hours hrs] Total Pending Bookings Found: " . $pendingBookings->count());

            foreach ($pendingBookings as $booking) {
                $client = $booking->user;
                $mailInstance = new PendingPaymentReminderMail($booking);
                SendEmailJob::dispatch($client->email, $mailInstance);

                // Log::info("✅ [$hours hrs] Email job dispatched for booking ID: {$booking->id}, email: {$client->email}");
            }
        }

        $expiredBookings = Booking::with(['tourbooking.location.country', 'user'])
            ->whereIn('booking_status', ['confirmed'])
            ->whereIn('booking_type', ['tour', 'ticket'])
            ->whereNotNull('service_date')
            ->get();

        foreach ($expiredBookings as $booking) {
            $fleetBooking = null;
            $client = $booking->user;

            // ✅ Get timezone from country (fallback to Asia/Kuala_Lumpur)
            $countryTimezone = optional($booking->tourbooking?->location?->country)->timezones;
            $timezoneData = json_decode($countryTimezone, true);
            $zoneName = $timezoneData[0]['zoneName'] ?? 'Asia/Kuala_Lumpur';

            // ✅ Convert service date and current time to country-specific timezone
            $serviceDate = $booking->service_date;
            $now = Carbon::now($zoneName);
            // ✅ Check if difference is less than 24 hours
            if ($now->diffInHours($serviceDate, false) < 24) {
                // Get booking detail based on type
                if ($booking->booking_type === 'tour') {
                    $fleetBooking = TourBooking::where('booking_id', $booking->id)->first();
                } elseif ($booking->booking_type === 'ticket') {
                    $fleetBooking = TourBooking::where('booking_id', $booking->id)->first(); // or TicketBooking if available
                }

                $booking->update(['booking_status' => 'cancelled']);

                // Send cancellation emails
                $cancelEmail = new BookingCancel(
                    $fleetBooking,
                    $client->first_name,
                    null,
                    null,
                    null,
                    null,
                    $booking->booking_type,
                    null
                );
                SendEmailJob::dispatch($client->email, $cancelEmail);

                // Notify admins
                $admin = new BookingCancel(
                    $fleetBooking,
                    'Admin',
                    null,
                    null,
                    null,
                    null,
                    $booking->booking_type,
                    null
                );
                $adminEmails = [
                    config('mail.notify_tour'),
                    config('mail.notify_info'),
                    config('mail.notify_account')
                ];
                foreach ($adminEmails as $adminEmail) {
                    SendEmailJob::dispatch($adminEmail, $admin);
                }
            }
        }
    }

}
