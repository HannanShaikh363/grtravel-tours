<?php

namespace App\Jobs;

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
use Carbon\Carbon;

class CreateBookingPDFJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $bookingData;
    protected $email;
    protected $fleetBooking;
    protected $user;

    /**
     * Create a new job instance.
     */
    public function __construct($bookingData, $email, $fleetBooking, $user)
    {
        $this->bookingData = $bookingData;
        $this->email = $email;
        $this->fleetBooking = $fleetBooking;
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
        $id = $this->fleetBooking->id;
        $pdfFilePathVoucher = "{$directoryPath}/booking_voucher_{$timestamp}_{$id}.pdf";
        $pdfFilePathInvoice = "{$directoryPath}/booking_invoice_{$timestamp}_{$id}.pdf";
        $pdfFilePathAdminVoucher = "{$directoryPath}/booking_admin_voucher_{$timestamp}_{$id}.pdf";

        // Generate PDFs
        $pdf = Pdf::loadView('email.transfer.booking_voucher', $this->bookingData);
        $pdf->save($pdfFilePathVoucher);
        chown($pdfFilePathVoucher, 'www-data');
        chmod($pdfFilePathVoucher, 0664);

        $pdf = Pdf::loadView('email.transfer.booking_invoice', $this->bookingData);
        $pdf->save($pdfFilePathInvoice);
        chown($pdfFilePathInvoice, 'www-data');
        chmod($pdfFilePathInvoice, 0664);

        $pdf = Pdf::loadView('email.transfer.booking_to_admin_voucher', $this->bookingData);
        $pdf->save($pdfFilePathAdminVoucher);
        chown($pdfFilePathAdminVoucher, 'www-data');
        chmod($pdfFilePathAdminVoucher, 0664);

    
        Mail::to($this->email)
            ->send(new BookingMail($this->bookingData, $pdfFilePathVoucher, $passengerName));
        
        Mail::to($this->email)
            ->send(new TransferBookingInvoiceMail($this->bookingData, $pdfFilePathInvoice, $passengerName));

        Mail::to(config('mail.notify_tour'))
            ->send(new BookingMailToAdmin($this->bookingData, $pdfFilePathAdminVoucher, $passengerName));

        Mail::to(config('mail.notify_info'))
            ->send(new BookingMailToAdmin($this->bookingData, $pdfFilePathAdminVoucher, $passengerName));

        Mail::to(config('mail.notify_account'))
            ->send(new BookingMailToAdmin($this->bookingData, $pdfFilePathAdminVoucher, $passengerName));

    }
}
