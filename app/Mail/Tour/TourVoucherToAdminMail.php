<?php

namespace App\Mail\Tour;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TourVoucherToAdminMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public $bookingData;
    public $pdfFilePath;
    public $passenger_full_name;
    public $passenger_contact_number;
    public $seating_capacity;
    public $id;
    public $pick_time;
    public $tour_date;
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
    public $agentLogo;
    public $netRate;
    public $netCurrency;
    public $reservation_id;
    public $voucher_code;
    public $voucher;
    public $discountedPrice;
    public $discount;
    public $currency;

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
            ? ($this->bookingData['is_updated'] == 1 ? 'TOUR VOUCHER UPDATED' : 'TOUR VOUCHER')
            : ($this->bookingData['is_updated'] == 1 ? 'TOUR VOUCHER/UNPAID UPDATED' : 'TOUR VOUCHER/UNPAID');
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
            'seating_capacity' => $this->seating_capacity,
            'pick_time' => $this->pick_time,
            'tour_date`' => $this->tour_date,
            'pickup_address' => $this->pickup_address,
            'agentCompanyName' => $this->agentCompanyName,
            'agentCompanyNumber' => $this->agentCompanyNumber,
            'agentCompanyAddress' => $this->agentCompanyAddress,
            'agentCompanyCity' => $this->agentCompanyCity,
            'agentCompanyZip' => $this->agentCompanyZip,
            'countryName' => $this->countryName,
            'booking_date' => $this->booking_date,
            'booking_cost' => $this->booking_cost,
            'hirerName' => $this->hirerName,
            'base_price' => $this->base_price,
            'booking_status' => $this->booking_status,
            'netRate' => $this->netRate,
            'netCurrency' => $this->netCurrency,
            'reservation_id' => $this->reservation_id,
            'voucher_code' => $this->voucher_code,
            'voucher' => $this->voucher,
            'discountedPrice' => $this->discountedPrice,
            'discount' => $this->discount,
            'currency' => $this->currency,
        ];

        return new Content(
            view: 'email.tour.tour_voucher_admin_email',
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
                ->as('TourBookingVoucher.pdf') // Name the attachment as 'booking.pdf'
                ->withMime('application/pdf') // Set the MIME type
        ];
    }
}
