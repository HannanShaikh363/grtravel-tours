<?php

namespace App\Jobs;

use App\Mail\Hotel\HotelBookingInvoiceMail;
use App\Mail\Hotel\HotelBookingVoucherMail;
use App\Mail\Hotel\HotelVoucherToAdminMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;

class HotelBookingPDFJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $bookingData;
    protected $email;
    protected $hotelBooking;
    protected $user;

    /**
     * Create a new job instance.
     */
    public function __construct($bookingData, $email, $hotelBooking, $user)
    {
        $this->bookingData = $bookingData;
        $this->email = $email;
        $this->hotelBooking = $hotelBooking;
        $this->user = $user;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $passengerName = $this->user->first_name . ' ' . $this->user->last_name;
        $directoryPath = public_path("bookings/hotel");
        if (!file_exists($directoryPath)) {
            mkdir($directoryPath, 0755, true);
        }

        $timestamp = now()->format('Ymd');
        $id = $this->hotelBooking->booking_id;
        if ($this->hotelBooking->booking->booking_status === 'vouchered') {
            $pdfFilePathInvoice = "{$directoryPath}/hotel_invoice_paid_{$timestamp}_{$id}.pdf";
        } else {
            $pdfFilePathInvoice = "{$directoryPath}/hotel_booking_invoice_{$timestamp}_{$id}.pdf";
        }
        $pdfFilePathVoucher = "{$directoryPath}/hotel_booking_voucher_{$timestamp}_{$id}.pdf";
        $pdfFilePathAdminVoucher = "{$directoryPath}/hotel_booking_admin_voucher_{$timestamp}_{$id}.pdf";
        
        // Generate PDFs
        $pdf = Pdf::loadView('email.hotel.hotel_booking_voucher', $this->bookingData);
        $pdf->save($pdfFilePathVoucher);
        chown($pdfFilePathVoucher, 'www-data');
        chmod($pdfFilePathVoucher, 0664);

        $pdf = Pdf::loadView('email.hotel.hotel_booking_invoice', $this->bookingData);
        $pdf->save($pdfFilePathInvoice);
        chown($pdfFilePathInvoice, 'www-data');
        chmod($pdfFilePathInvoice, permissions: 0664);

        $pdf = Pdf::loadView('email.hotel.hotel_booking_admin_voucher', $this->bookingData);
        $pdf->save($pdfFilePathAdminVoucher);
        chown($pdfFilePathAdminVoucher, 'www-data');
        chmod($pdfFilePathAdminVoucher, 0664);

        // Send Emails
        $admin1 = 'tours@grtravel.net';
        $admin2 = 'info@grtravel.net';

        Mail::to($this->email)
            ->send(new HotelBookingVoucherMail($this->bookingData, $pdfFilePathVoucher, $passengerName));

        Mail::to($this->email)
            ->send(new HotelBookingInvoiceMail($this->bookingData, $pdfFilePathInvoice, $passengerName));

        Mail::to($admin1)
            ->send(new HotelVoucherToAdminMail($this->bookingData, $pdfFilePathAdminVoucher, $passengerName));
        Mail::to($admin2)
            ->send(new HotelVoucherToAdminMail($this->bookingData, $pdfFilePathAdminVoucher, $passengerName));
    }
}
