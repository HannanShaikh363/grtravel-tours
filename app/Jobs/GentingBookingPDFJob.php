<?php

namespace App\Jobs;

use App\Mail\Genting\GentingBookingInvoiceMail;
use App\Mail\Genting\GentingBookingVoucherMail;
use App\Mail\Genting\GentingVoucherToAdminMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;

class GentingBookingPDFJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $bookingData;
    protected $email;
    protected $gentingBooking;
    protected $user;

    /**
     * Create a new job instance.
     */
    public function __construct($bookingData, $email, $gentingBooking, $user)
    {
        $this->bookingData = $bookingData;
        $this->email = $email;
        $this->gentingBooking = $gentingBooking;
        $this->user = $user;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $passengerName = $this->user->first_name . ' ' . $this->user->last_name;

        $directoryPath = public_path("bookings/genting");
        if (!file_exists($directoryPath)) {
            mkdir($directoryPath, 0755, true);
        }

        $timestamp = now()->format('Ymd');
        $id = $this->gentingBooking->booking_id;
        if ($this->gentingBooking->booking->booking_status === 'vouchered') {
            $pdfFilePathInvoice = "{$directoryPath}/genting_invoice_paid_{$timestamp}_{$id}.pdf";
        } else {
            $pdfFilePathInvoice = "{$directoryPath}/genting_booking_invoice_{$timestamp}_{$id}.pdf";
        }
        $pdfFilePathVoucher = "{$directoryPath}/genting_booking_voucher_{$timestamp}_{$id}.pdf";
        $pdfFilePathAdminVoucher = "{$directoryPath}/genting_booking_admin_voucher_{$timestamp}_{$id}.pdf";

        // Generate PDFs
        $pdf = Pdf::loadView('email.genting.genting_booking_voucher', $this->bookingData);
        $pdf->save($pdfFilePathVoucher);
        chown($pdfFilePathVoucher, 'www-data');
        chmod($pdfFilePathVoucher, 0664);

        $pdf = Pdf::loadView('email.genting.genting_booking_invoice', $this->bookingData);
        $pdf->save($pdfFilePathInvoice);
        chown($pdfFilePathInvoice, 'www-data');
        chmod($pdfFilePathInvoice, permissions: 0664);

        $pdf = Pdf::loadView('email.genting.genting_booking_admin_voucher', $this->bookingData);
        $pdf->save($pdfFilePathAdminVoucher);
        chown($pdfFilePathAdminVoucher, 'www-data');
        chmod($pdfFilePathAdminVoucher, 0664);

        // Send Emails
        $admin1 = config('mail.notify_genting');
        $admin2 = config('mail.notify_info');
        $admin3 = config('mail.notify_account');

        Mail::to($this->email)
            ->send(new GentingBookingVoucherMail($this->bookingData, $pdfFilePathVoucher, $passengerName));

        Mail::to($this->email)
            ->send(new GentingBookingInvoiceMail($this->bookingData, $pdfFilePathInvoice, $passengerName));

        Mail::to($admin1)
            ->send(new GentingVoucherToAdminMail($this->bookingData, $pdfFilePathAdminVoucher, $passengerName));
        Mail::to($admin2)
            ->send(new GentingVoucherToAdminMail($this->bookingData, $pdfFilePathAdminVoucher, $passengerName));
        Mail::to($admin3)
            ->send(new GentingVoucherToAdminMail($this->bookingData, $pdfFilePathAdminVoucher, $passengerName));
    }
}
