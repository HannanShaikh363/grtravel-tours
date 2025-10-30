<?php

namespace App\Jobs;

use App\Mail\Tour\TourVoucherToAdminMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Mail\BookingMail;
use App\Mail\TransferBookingInvoiceMail;
use App\Mail\BookingMailToAdmin;
use App\Mail\Tour\TourBookingInvoiceMail;
use App\Mail\Tour\TourBookingVoucherMail;
use Carbon\Carbon;

class TourBookingPDFJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $bookingData;
    protected $email;
    protected $tourBooking;
    protected $user;

    /**
     * Create a new job instance.
     */
    public function __construct($bookingData, $email, $tourBooking, $user)
    {
        $this->bookingData = $bookingData;
        $this->email = $email;
        $this->tourBooking = $tourBooking;
        $this->user = $user;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $passengerName = $this->user->first_name . ' ' . $this->user->last_name;

        $directoryPath = public_path("bookings");
        if (!file_exists($directoryPath)) {
            mkdir($directoryPath, 0755, true);
        }

        $timestamp = now()->format('Ymd');
        $id = $this->tourBooking->booking_id;
        if ($this->tourBooking->type === 'ticket') {
            if ($this->tourBooking->booking->booking_status === 'vouchered') {
                $pdfFilePathInvoice = "{$directoryPath}/ticket_invoice_paid_{$timestamp}_{$id}.pdf";
            } else {
                $pdfFilePathInvoice = "{$directoryPath}/ticket_booking_invoice_{$timestamp}_{$id}.pdf";
            }
            $pdfFilePathVoucher = "{$directoryPath}/ticket_booking_voucher_{$timestamp}_{$id}.pdf";
            $pdfFilePathAdminVoucher = "{$directoryPath}/ticket_booking_admin_voucher_{$timestamp}_{$id}.pdf";
        } else {
            if ($this->tourBooking->booking->booking_status === 'vouchered') {
                $pdfFilePathInvoice = "{$directoryPath}/tour_invoice_paid_{$timestamp}_{$id}.pdf";
            } else {
                $pdfFilePathInvoice = "{$directoryPath}/tour_booking_invoice_{$timestamp}_{$id}.pdf";
            }
            $pdfFilePathVoucher = "{$directoryPath}/tour_booking_voucher_{$timestamp}_{$id}.pdf";
            $pdfFilePathAdminVoucher = "{$directoryPath}/tour_booking_admin_voucher_{$timestamp}_{$id}.pdf";
        }

        // Generate PDFs
        $pdf = Pdf::loadView('email.tour.tour_booking_voucher', $this->bookingData);
        $pdf->save($pdfFilePathVoucher);
        chown($pdfFilePathVoucher, 'www-data');
        chmod($pdfFilePathVoucher, 0664);

        $pdf = Pdf::loadView('email.tour.tour_booking_invoice', $this->bookingData);
        $pdf->save($pdfFilePathInvoice);
        chown($pdfFilePathInvoice, 'www-data');
        chmod($pdfFilePathInvoice, permissions: 0664);

        $pdf = Pdf::loadView('email.tour.tour_booking_admin_voucher', $this->bookingData);
        $pdf->save($pdfFilePathAdminVoucher);
        chown($pdfFilePathAdminVoucher, 'www-data');
        chmod($pdfFilePathAdminVoucher, 0664);

        // Send Emails
        $admin1 = config('mail.notify_tour');
        $admin2 = config('mail.notify_info');
        $admin3 = config('mail.notify_account');

        Mail::to($this->email)
            ->send(new TourBookingVoucherMail($this->bookingData, $pdfFilePathVoucher, $passengerName));

        Mail::to($this->email)
            ->send(new TourBookingInvoiceMail($this->bookingData, $pdfFilePathInvoice, $passengerName));

        Mail::to($admin1)
            ->send(new TourVoucherToAdminMail($this->bookingData, $pdfFilePathAdminVoucher, $passengerName));
        Mail::to($admin2)
            ->send(new TourVoucherToAdminMail($this->bookingData, $pdfFilePathAdminVoucher, $passengerName));
        Mail::to($admin3)
            ->send(new TourVoucherToAdminMail($this->bookingData, $pdfFilePathAdminVoucher, $passengerName));
    }
}
