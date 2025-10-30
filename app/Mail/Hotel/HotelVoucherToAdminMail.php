<?php

namespace App\Mail\Hotel;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class HotelVoucherToAdminMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public $bookingData;
    public $pdfFilePath;
    public $passenger_first_name;
    public $passenger_last_name;
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
    public $hotel_name;
    public $package;
    public $room_type;
    public $number_of_rooms;
    public $extra_bed_for_child;
    public $reservation_id;
    public $confirmation_id;
    public $deadlineDate;

    public function __construct($bookingData, $pdfFilePath, $passenger_first_name)
    {
        $this->bookingData = $bookingData;
        $this->pdfFilePath = $pdfFilePath;
        $this->passenger_first_name = $passenger_first_name;
    }
    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $subject = $this->bookingData['is_updated'] ? 'HOTEL BOOKING VOUCHER UPDATED' : 'HOTEL BOOKING VOUCHER';
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
            'id' => $this->id,
            'passenger_first_name' => $this->passenger_first_name,
            'passenger_last_name' => $this->passenger_last_name,
            'hotel_name' => $this->hotel_name,
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
            'package' => $this->package,
            'room_type' => $this->room_type,
            'number_of_rooms' => $this->number_of_rooms,
            'extra_bed_for_child' => $this->extra_bed_for_child,
            'reservation_id' => $this->reservation_id,
            'confirmation_id' => $this->confirmation_id,
            'deadlineDate' => $this->deadlineDate,
        ];

        return new Content(
            view: 'email.hotel.hotel_voucher_admin_email',
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
                ->as('HotelBookingVoucher.pdf') // Name the attachment as 'booking.pdf'
                ->withMime('application/pdf') // Set the MIME type
        ];
    }
}
