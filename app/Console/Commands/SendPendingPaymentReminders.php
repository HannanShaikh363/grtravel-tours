<?php

namespace App\Console\Commands;
use App\Mail\BookingCancel;
use App\Models\Booking;
use App\Models\FleetBooking;
use App\Models\GentingBooking;
use Illuminate\Console\Command;
use App\Notifications\PendingPaymentReminder;
use App\Services\BookingService;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;
use App\Jobs\SendEmailJob;
use App\Mail\PendingPaymentReminderMail;
use Illuminate\Support\Facades\Log;

class SendPendingPaymentReminders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'send:pending-payment-reminders';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send reminder emails to clients with pending payments';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $dropOffName = null;
        $pickUpName = null;
        $timeIntervals = [48, 24, 12]; // Hours before service date
        // Log::info('🔍 [SendPendingPaymentReminders] Command started.');
        foreach ($timeIntervals as $hours) {
            // $this->info("$hours Checking bookings with service_date between ".Carbon::now()->addHours($hours)->startOfDay()." and ". Carbon::now()->addHours($hours)->endOfDay());

            $pendingBookings = Booking::whereIn('booking_status', ['confirmed'])
                ->where('created_at', '<=', Carbon::now()->subDay()) 
                ->whereBetween('service_date', [ // Check reminders based on service_date
                    Carbon::now()->addHours($hours)->startOfDay(),
                    Carbon::now()->addHours($hours)->endOfDay()
                ])
                ->whereIn('booking_type', ['transfer'])
                ->with([
                    'user', 
                    'fleetbooking',
                    'fleetbooking.toLocation',
                    'fleetbooking.fromLocation',
                    'fleetbooking.toLocation.meetingPoints',
                    'fleetbooking.fromLocation.meetingPoints',
                    'fleetbooking.transferBookingHotel'
                ])->get();
            // Log::info('📌 Total Pending Bookings Found: ' . $pendingBookings->count());
            foreach ($pendingBookings as $booking) {
                $client = $booking->user;
                $mailInstance = new PendingPaymentReminderMail($booking);
                SendEmailJob::dispatch($client->email, $mailInstance);
                
                // Send reminder email to each client with a pending booking
                // $client->notify(new PendingPaymentReminder($booking));
                // Log::info('✅ Email job dispatched for booking ID: ' . $booking->email);
            }

            $expiredBookings = Booking::whereIn('booking_status', ['confirmed'])
                ->where('service_date', '<', Carbon::now()->toDateString()) // Past service date
                ->get();

            foreach ($expiredBookings as $booking) {
                $client = $booking->user;
                if($booking->booking_type === 'transfer'){
                    $fleetBooking = FleetBooking::where('booking_id', $booking->id)->first();
                }
                if($booking->booking_type === 'genting_hotel'){
                    $fleetBooking = GentingBooking::where('booking_id', $booking->id)->first();
                }
                $booking->update(['booking_status' => 'cancelled']);
                $cancelEmail = new BookingCancel( $fleetBooking, $booking->user->first_name, null, null , null , null, $booking->booking_type, null );
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

        // Log::info('✅ [SendPendingPaymentReminders] Command completed.');
        // $this->info('Pending payment reminders sent successfully!');
    }
}
