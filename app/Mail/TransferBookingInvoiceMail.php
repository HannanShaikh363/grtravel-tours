<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TransferBookingInvoiceMail  extends Mailable
{
    use Queueable, SerializesModels;
    /**
     * Create a new message instance.
     */


    public $bookingData;
    public $pdfFilePath;
    public $passenger_full_name;
    public $passenger_contact_number;
    public $vehicle_seating_capacity;
    public $id;
    public $pick_time;
    public $pick_date;
    public $pickup_address;
    public $dropoff_address;
    public $flight_number;
    public $arrival_flight_number;
    public $flight_departure_time;
    public $flight_arrival_time;
    public $vehicle;
    public $agentCompanyName;
    public $agentCompanyNumber;
    public $agentCompanyAddress;
    public $agentCompanyCity;
    public $agentCompanyZip;
    public $countryName;
    public $booking_date;
    public $booking_cost;
    public $return_flight_number;
    public $return_flight_time;
    public $haveMeetingPointDesc;
    public $meeting_point_name;
    public $meeting_point_images;
    public $hirerName;
    public $base_price;
    public $return_pickup_address;
    public $return_pickup_date;
    public $return_pickup_time;
    public $booking_status;
    public $return_arrival_flight_number;
    public $package;
    public $paymentMode;
    public $currency;
    public $discountedPrice;
    public $discount;
    public $voucher;

    public function __construct($bookingData, $pdfFilePath, $passenger_full_name)
    {
        $this->bookingData = $bookingData;
        $this->pdfFilePath = $pdfFilePath;
        $this->passenger_full_name = $passenger_full_name;
    }
    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $subject = $this->bookingData['booking_status'] === 'vouchered'
        ? ($this->bookingData['is_updated'] == 1 ? 'TRANSFER PAYMENT RECEIPT UPDATED' : 'TRANSFER BOOKING PAYMENT RECEIPT')
        : ($this->bookingData['is_updated'] == 1 ? 'TRANSFER BOOKING INVOICE UPDATED' : 'TRANSFER BOOKING INVOICE/UNPAID');
        
        return new Envelope(
            subject: $subject,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {

        $bookingData = [
            'passenger_full_name' => $this->passenger_full_name,
            'passenger_contact_number' => $this->passenger_contact_number,
            'id' => $this->id,
            'vehicle_seating_capacity' => $this->vehicle_seating_capacity,
            'pick_time' => $this->pick_time,
            'pick_date`' => $this->pick_date,
            'pickup_address' => $this->pickup_address,
            'dropoff_address' => $this->dropoff_address,
            'flight_number' => $this->flight_number,
            'arrival_flight_number' => $this->arrival_flight_number,
            'flight_arrival_time' => $this->flight_arrival_time,
            'flight_departure_time' => $this->flight_departure_time,
            'vehicle' => $this->vehicle,
            'agentCompanyName' => $this->agentCompanyName,
            'agentCompanyNumber' => $this->agentCompanyNumber,
            'agentCompanyAddress' => $this->agentCompanyAddress,
            'agentCompanyCity' => $this->agentCompanyCity,
            'agentCompanyZip' => $this->agentCompanyZip,
            'countryName' => $this->countryName,
            'booking_date' => $this->booking_date,
            'booking_cost' => $this->booking_cost,
            'pick_date' => $this->pick_date,
            'return_flight_number' => $this->return_flight_number,
            'return_arrival_flight_number' => $this->return_arrival_flight_number,
            'return_flight_time' => $this->return_flight_time,
            'return_pickup_address' => $this->return_pickup_address,
            'meeting_point_desc' => $this->haveMeetingPointDesc,
            'meeting_point_name' => $this->meeting_point_name,
            'meeting_point_images' => $this->meeting_point_images,
            'return_pickup_date' => $this->return_pickup_date,
            'return_pickup_time' => $this->return_pickup_time,
            'hirerName' => $this->hirerName,
            'base_price' => $this->base_price,
            'booking_status' => $this->booking_status,
            'package' => $this->package,
            'paymentMode' => $this->paymentMode,
            'currency' => $this->currency,
            'discountedPrice' => $this->discountedPrice,
            'discount' => $this->discount,
            'voucher' => $this->voucher,
        ];

        return new Content(
            view: 'email.transfer.transfer_invoice_email',
            with: array_merge($bookingData, ['bookingData' => $bookingData]),
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromPath($this->pdfFilePath) // Use the passed PDF file path
                ->as('bookingInvoice.pdf') // Name the attachment as 'booking.pdf'
                ->withMime('application/pdf') // Set the MIME type
        ];
    }
}
